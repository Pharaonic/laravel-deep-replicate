<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\DeepReplicate\Engine\Graph;
use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;
use Pharaonic\Laravel\DeepReplicate\Engine\LinkAssignment;
use Pharaonic\Laravel\DeepReplicate\Exceptions\CrossConnectionReplicationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;

/**
 * MorphTo: the parent holds the morph id and type. Targets are read with one
 * query per concrete type, resolved through the morph map.
 *
 * @internal
 */
final class MorphToHandler implements RelationHandler
{
    use PreservesReusedKeys;

    public function supports(Relation $relation): bool
    {
        return get_class($relation) === MorphTo::class;
    }

    public function supportsReuse(): bool
    {
        return true;
    }

    public function isManyToMany(): bool
    {
        return false;
    }

    public function staticRelated(Relation $relation): ?Model
    {
        return null;
    }

    public function parentColumns(Relation $relation): array
    {
        /** @var MorphTo $relation */
        return [$relation->getForeignKeyName(), $relation->getMorphType()];
    }

    public function fetch(Relation $relation, array $parents, string $name, string $path, array $filters, bool $withoutGlobalScopes, Graph $graph): array
    {
        /** @var MorphTo $relation */
        $foreignKey = $relation->getForeignKeyName();
        $morphType = $relation->getMorphType();
        $connection = $graph->definition->source->getConnection();

        foreach ($filters as $filter) {
            $filter($relation);
        }

        $byType = [];

        foreach ($parents as $index => $parent) {
            $type = $parent->getAttribute($morphType);
            $id = $parent->getAttribute($foreignKey);

            if ($type === null || $type === '' || $id === null || ! is_scalar($type) || ! is_scalar($id)) {
                continue;
            }

            $byType[(string) $type][$index] = (string) $id;
        }

        $found = [];

        foreach ($byType as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                throw InvalidRelationshipPathException::unknownMorphType(get_class($parents[0]), $path, $type);
            }

            /** @var Model $instance */
            $instance = new $class;

            if ($instance->getConnectionName() === null) {
                $instance->setConnection($relation->getParent()->getConnection()->getName());
            }

            if ($instance->getConnection() !== $connection) {
                throw CrossConnectionReplicationException::for($class, $path, $connection->getName(), $instance->getConnection()->getName());
            }

            $ownerKey = $relation->getOwnerKeyName() ?: $instance->getKeyName();
            $query = $instance->newQuery();

            if ($withoutGlobalScopes) {
                $query->withoutGlobalScopes();
            }

            $query->mergeConstraintsFrom($relation->getQuery())
                ->whereIn($instance->qualifyColumn($ownerKey), array_values(array_unique($ids)))
                ->orderBy($instance->qualifyColumn($ownerKey));

            $dictionary = [];

            foreach ($query->get() as $model) {
                /** @var Model $model */
                $key = $model->getAttribute($ownerKey);
                $dictionary[is_scalar($key) ? (string) $key : ''] = $model;
            }

            foreach ($ids as $index => $id) {
                if (isset($dictionary[$id])) {
                    $found[$index] = [[$dictionary[$id], null]];
                }
            }
        }

        $targets = [];

        foreach (array_keys($parents) as $index) {
            $targets[] = $found[$index] ?? [];
        }

        return $targets;
    }

    public function connect(Graph $graph, GraphNode $parent, GraphNode $target, Relation $relation, ?Model $pivot, string $path): void
    {
        /** @var MorphTo $relation */
        $ownerKey = $relation->getOwnerKeyName() ?: $target->source->getKeyName();
        $morphType = $relation->getMorphType();

        $parent->assign($target->isCopy()
            ? new LinkAssignment($relation->getForeignKeyName(), $target, $ownerKey, null, $path)
            : new LinkAssignment($relation->getForeignKeyName(), null, '', $this->preserved($parent, $relation->getForeignKeyName(), $target, $ownerKey), $path));

        // Keep the stored discriminator exactly as the source has it.
        $parent->assign(new LinkAssignment($morphType, null, '', $parent->source->getAttributes()[$morphType] ?? null, $path));
    }
}
