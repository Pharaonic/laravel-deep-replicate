<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\CrossConnectionReplicationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\MissingLoadedRelationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ProtectedRelationshipAttributeException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationLimitExceededException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsavedSourceModelException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsupportedRelationshipException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsupportedReuseException;
use Pharaonic\Laravel\DeepReplicate\PlanningWarning;
use Pharaonic\Laravel\DeepReplicate\Relations\BelongsToManyHandler;
use Pharaonic\Laravel\DeepReplicate\Relations\Handlers;
use Pharaonic\Laravel\DeepReplicate\Relations\RelationHandler;

/**
 * Stage A: validates the selection and reads the graph, without any write.
 *
 * The graph is read level by level. Parents of the same class reached through
 * the same path are batched, so each relation costs one query per chunk of
 * parents (one per morph type for MorphTo), never one per parent.
 *
 * @internal
 */
final class GraphPlanner
{
    private RelationResolver $resolver;

    private Handlers $handlers;

    public function __construct(RelationResolver $resolver, Handlers $handlers)
    {
        $this->resolver = $resolver;
        $this->handlers = $handlers;
    }

    public function plan(Definition $definition): Graph
    {
        $source = $definition->source;

        if (! $source->exists || IdentityMap::tryKeyFor($source) === null) {
            throw UnsavedSourceModelException::for(get_class($source));
        }

        $deepest = $definition->tree->maxActiveDepth();

        if ($deepest !== null && $deepest->depth > $definition->maxDepth) {
            throw ReplicationLimitExceededException::depth($deepest->path, $deepest->depth, $definition->maxDepth);
        }

        $this->validateStatically($definition, $definition->tree->root, $source);

        $graph = new Graph($definition);

        if ($definition->withoutGlobalScopes) {
            $graph->warn(
                PlanningWarning::GLOBAL_SCOPES_REMOVED,
                null,
                'withoutGlobalScopes() removes every global scope, tenant and soft-delete scopes included, from the relation queries.'
            );
        }

        /** @var list<array{0: GraphNode, 1: Model, 2: RuleNode}> $frontier */
        $frontier = [[$graph->root, $source, $definition->tree->root]];
        $expanded = [$graph->root->key.'|' => true];

        while ($frontier !== []) {
            $groups = [];

            foreach ($frontier as [$node, $instance, $rule]) {
                $group = $rule->path.'|'.get_class($instance);
                $groups[$group]['rule'] = $rule;
                $groups[$group]['items'][$node->key] = [$node, $instance];
            }

            $next = [];

            foreach ($groups as $group) {
                foreach ($group['rule']->children as $child) {
                    $this->expand($graph, $child, array_values($group['items']), $next, $expanded);
                }
            }

            $frontier = $next;
        }

        $this->validateManagedAttributes($graph);

        return $graph;
    }

    /**
     * @param  list<array{0: GraphNode, 1: Model}>  $items
     * @param  list<array{0: GraphNode, 1: Model, 2: RuleNode}>  $next
     * @param  array<string, true>  $expanded
     */
    private function expand(Graph $graph, RuleNode $child, array $items, array &$next, array &$expanded): void
    {
        $definition = $graph->definition;
        $sample = $items[0][1];
        $template = $this->template($sample, $child);
        $handler = $this->handlerFor($definition, $template, get_class($sample), $child);

        if ($handler === null || ! $child->isActive()) {
            return;
        }

        $this->guardConnection($definition, $template->getRelated(), $child->path);

        $parentColumns = $handler->parentColumns($template);
        $loaded = isset($definition->loaded[$child->path]);

        foreach (array_chunk($items, $definition->chunk) as $batch) {
            $parents = array_map(static function (array $item): Model {
                return $item[1];
            }, $batch);

            $targets = $loaded
                ? $this->loadedTargets($graph, $handler, $template, $parents, $child)
                : $handler->fetch(
                    $this->template($sample, $child),
                    array_map([$this, 'probe'], $parents),
                    $child->name,
                    $child->path,
                    $definition->filters[$child->path] ?? [],
                    $definition->withoutGlobalScopes,
                    $graph
                );

            foreach ($batch as $index => [$parentNode, $parentInstance]) {
                foreach ($parentColumns as $column) {
                    $parentNode->manage($column, $child->path);
                }

                $found = $targets[$index] ?? [];

                if ($found === [] && $parentColumns !== [] && $parentInstance->getAttribute($parentColumns[0]) !== null) {
                    $graph->warn(
                        PlanningWarning::MISSING_TARGET,
                        $child->path,
                        'A ['.get_class($parentInstance)."] references a [{$child->path}] record that the relation query did not return "
                        .'(missing, filtered or hidden by a scope); its foreign key is kept unchanged on the copy.'
                    );
                }

                foreach ($found as [$target, $pivot]) {
                    $this->guardConnection($definition, $target, $child->path);

                    $node = $graph->resolve($target, $child->strategy, $child->depth, $child->path);
                    $handler->connect($graph, $parentNode, $node, $template, $pivot, $child->path);

                    $marker = $node->key.'|'.$child->path;

                    if ($node->isCopy() && $child->children !== [] && ! isset($expanded[$marker])) {
                        $expanded[$marker] = true;
                        $next[] = [$node, $target, $child];
                    }
                }
            }
        }
    }

    /**
     * @param  list<Model>  $parents
     * @param  Relation<Model, Model, mixed>  $template
     * @return list<list<array{0: Model, 1: Model|null}>>
     */
    private function loadedTargets(Graph $graph, RelationHandler $handler, Relation $template, array $parents, RuleNode $child): array
    {
        $pivots = $template instanceof BelongsToMany && $handler instanceof BelongsToManyHandler ? [$template, $handler] : null;
        $targets = [];
        $warned = false;

        foreach ($parents as $index => $parent) {
            if (! $parent->relationLoaded($child->name)) {
                throw MissingLoadedRelationException::for(get_class($parent), $child->path);
            }

            $value = $parent->getRelation($child->name);
            $targets[$index] = [];

            foreach ($value instanceof Collection ? $value->all() : [$value] as $target) {
                if (! $target instanceof Model) {
                    continue;
                }

                $pivot = null;

                if ($pivots !== null) {
                    [$relation, $pivotHandler] = $pivots;
                    $accessor = $relation->getPivotAccessor();
                    $pivot = $target->relationLoaded($accessor) ? $target->getRelation($accessor) : null;

                    if (! $pivot instanceof Model) {
                        throw MissingLoadedRelationException::for(get_class($target), $child->path.'.'.$accessor);
                    }

                    $missing = $pivotHandler->missingColumns($relation, $pivot);

                    if ($missing !== [] && ! $warned) {
                        $warned = true;
                        $graph->warn(
                            PlanningWarning::UNLOADED_PIVOT_COLUMNS,
                            $child->path,
                            "The loaded pivots of [{$child->path}] do not carry the column(s) [".implode(', ', $missing)
                            .']; the new pivot rows get their database defaults. Load them with withPivot() to copy them.'
                        );
                    }
                }

                $targets[$index][] = [$target, $pivot];
            }
        }

        return array_values($targets);
    }

    private function validateStatically(Definition $definition, RuleNode $rule, Model $instance): void
    {
        foreach ($rule->children as $child) {
            $template = $this->template($instance, $child);
            $handler = $this->handlerFor($definition, $template, get_class($instance), $child);

            if ($handler === null || $child->children === []) {
                continue;
            }

            $related = $handler->staticRelated($template);

            if ($related !== null) {
                $this->validateStatically($definition, $child, $related->newInstance());
            }
        }
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    private function handlerFor(Definition $definition, Relation $relation, string $class, RuleNode $child): ?RelationHandler
    {
        $handler = $this->handlers->for($relation);

        if ($handler === null) {
            if (! $child->isActive()) {
                return null;
            }

            throw UnsupportedRelationshipException::for($class, $child->path, get_class($relation));
        }

        if ($child->strategy === Strategy::REUSE && ! $handler->supportsReuse()) {
            throw UnsupportedReuseException::for($class, $child->path, get_class($relation));
        }

        if (! $handler->isManyToMany()) {
            foreach (['copyPivot' => $definition->copyPivot, 'transformPivot' => $definition->pivotTransforms] as $method => $paths) {
                if (isset($paths[$child->path])) {
                    throw IncompatibleRelationOptionsException::pivotOption($method, $child->path, get_class($relation));
                }
            }
        }

        return $handler;
    }

    /**
     * @return Relation<Model, Model, mixed>
     */
    private function template(Model $parent, RuleNode $child): Relation
    {
        $blank = $parent->newInstance();

        // Captured by reference: the return type of noConstraints() differs between Laravel releases.
        $relation = null;

        Relation::noConstraints(function () use ($blank, $child, &$relation): void {
            $relation = $this->resolver->resolve($blank, $child->name, $child->path);
        });

        if ($relation === null) {
            throw new LogicException('Relation::noConstraints() did not run the callback.');
        }

        return $relation;
    }

    private function probe(Model $model): Model
    {
        $probe = $model->newInstance([], true);
        $probe->setRawAttributes($model->getAttributes(), true);

        return $probe;
    }

    private function guardConnection(Definition $definition, Model $model, string $path): void
    {
        $expected = $definition->source->getConnection();
        $actual = $model->getConnection();

        if ($actual !== $expected) {
            throw CrossConnectionReplicationException::for(get_class($model), $path, $expected->getName(), $actual->getName());
        }
    }

    private function validateManagedAttributes(Graph $graph): void
    {
        foreach ($graph->copyNodes() as $node) {
            $rule = $graph->definition->ruleFor($node->rulePath);
            $model = $node->source;
            $protected = $node->managed;
            $protected[$model->getKeyName()] = $node->path;
            $path = $node->path === '' ? null : $node->path;

            foreach ($rule->excludedAttributes() ?? [] as $attribute) {
                if (isset($protected[$attribute]) && $attribute !== $model->getKeyName()) {
                    throw ProtectedRelationshipAttributeException::for(get_class($model), $path, $attribute, 'exclude()');
                }
            }

            foreach (array_keys($rule->attributeOverrides()) as $attribute) {
                if (isset($protected[$attribute])) {
                    throw ProtectedRelationshipAttributeException::for(get_class($model), $path, $attribute, 'with()');
                }
            }

            foreach (array_keys($rule->generators()) as $attribute) {
                $ownKey = $attribute === $model->getKeyName() && ! $model->getIncrementing();

                if (isset($protected[$attribute]) && ! $ownKey) {
                    throw ProtectedRelationshipAttributeException::for(get_class($model), $path, $attribute, 'generate()');
                }
            }
        }
    }
}
