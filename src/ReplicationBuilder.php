<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Pharaonic\Laravel\DeepReplicate\Engine\Definition;
use Pharaonic\Laravel\DeepReplicate\Engine\ReplicationEngine;
use Pharaonic\Laravel\DeepReplicate\Engine\RuleTree;
use Pharaonic\Laravel\DeepReplicate\Engine\Strategy;
use Pharaonic\Laravel\DeepReplicate\Exceptions\BuilderAlreadyExecutedException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;
use ReflectionMethod;

/**
 * Selects the relation graph of one source model and replicates it.
 *
 * Nothing is read or written until dryRun() or replicate(). A builder is
 * single-use: once replicate() has run, successfully or not, it cannot run
 * again.
 */
final class ReplicationBuilder
{
    private Model $source;

    /** @var list<array{0: string, 1: string}> */
    private array $selections = [];

    private RelationRule $root;

    /** @var array<string, RelationRule> */
    private array $rules = [];

    /** @var array<string, list<callable>> */
    private array $filters = [];

    /** @var array<string, true> */
    private array $loaded = [];

    /** @var array<string, true> */
    private array $copyPivot = [];

    /** @var array<string, list<callable>> */
    private array $pivotTransforms = [];

    private bool $preserveTimestamps = false;

    private bool $preserveSoftDelete = false;

    private bool $withoutGlobalScopes = false;

    private bool $withoutEvents = false;

    private int $maxDepth = 10;

    private int $maxModels = 10000;

    private int $maxPivotRows = 50000;

    /** @var positive-int */
    private int $chunk = 500;

    /** @var array<string, list<callable>> */
    private array $callbacks = [];

    private bool $executed = false;

    public function __construct(Model $source)
    {
        $this->source = $source;
        $this->root = new RelationRule;
    }

    /**
     * Select paths: list entries are copied, keyed entries take their value
     * ("copy", "reuse" or "skip") as strategy.
     *
     * @param  array<int|string, mixed>  $rules
     */
    public function relations(array $rules): self
    {
        foreach ($rules as $key => $value) {
            if (is_int($key)) {
                $this->select(RuleTree::assertPath($value), Strategy::COPY);
            } else {
                $path = RuleTree::assertPath($key);
                $this->select($path, Strategy::normalize($path, $value));
            }
        }

        return $this;
    }

    /**
     * Create new records for these relation paths.
     */
    public function copy(string $path, string ...$paths): self
    {
        return $this->selectAll(Strategy::COPY, $path, array_values($paths));
    }

    /**
     * Link the replica to the existing records of these relation paths.
     */
    public function reuse(string $path, string ...$paths): self
    {
        return $this->selectAll(Strategy::REUSE, $path, array_values($paths));
    }

    /**
     * Neither traverse nor link these relation paths. Foreign keys already
     * held by a copied model are kept.
     */
    public function skip(string $path, string ...$paths): self
    {
        return $this->selectAll(Strategy::SKIP, $path, array_values($paths));
    }

    /**
     * Leave these attributes out of the root copy.
     *
     * @param  array<int, string>  $attributes
     */
    public function exclude(array $attributes): self
    {
        $this->root->exclude($attributes);

        return $this;
    }

    /**
     * Set attributes on the root copy, through its mutators and casts.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function with(array $attributes): self
    {
        $this->root->with($attributes);

        return $this;
    }

    /**
     * Transform the raw attributes of the root copy before it is inserted.
     *
     * @param  callable(array<string, mixed>, Model, ReplicationContext): array<string, mixed>  $callback
     */
    public function transform(callable $callback): self
    {
        $this->root->transform($callback);

        return $this;
    }

    /**
     * Generate one attribute of the root copy before it is inserted.
     *
     * @param  callable(Model, ReplicationContext): mixed  $callback
     */
    public function generate(string $attribute, callable $callback): self
    {
        $this->root->generate($attribute, $callback);

        return $this;
    }

    /**
     * Configure the copies made at one copied relation path.
     *
     * @param  callable(RelationRule): void  $callback
     */
    public function configure(string $path, callable $callback): self
    {
        $path = RuleTree::assertPath($path);
        $callback($this->rules[$path] = $this->rules[$path] ?? new RelationRule);

        return $this;
    }

    /**
     * Constrain the query of one selected relation path. The callback receives
     * the relation, as an eager-loading constraint does.
     *
     * @param  callable(\Illuminate\Database\Eloquent\Relations\Relation<\Illuminate\Database\Eloquent\Model, \Illuminate\Database\Eloquent\Model, mixed>): mixed  $callback
     */
    public function filter(string $path, callable $callback): self
    {
        $this->filters[RuleTree::assertPath($path)][] = $callback;

        return $this;
    }

    /**
     * Use the relations already loaded on the source models for these paths,
     * instead of querying them. A missing loaded relation is an error.
     */
    public function usingLoaded(string $path, string ...$paths): self
    {
        foreach (array_merge([$path], $paths) as $candidate) {
            $this->loaded[RuleTree::assertPath($candidate)] = true;
        }

        return $this;
    }

    /**
     * Copy the pivot attributes of these many-to-many paths. This is the
     * default; calling it documents the intent.
     */
    public function copyPivot(string $path, string ...$paths): self
    {
        foreach (array_merge([$path], $paths) as $candidate) {
            $this->copyPivot[RuleTree::assertPath($candidate)] = true;
        }

        return $this;
    }

    /**
     * Transform the non-linking attributes of each new pivot row of a path.
     *
     * @param  callable(array<string, mixed>, Model|null, ReplicationContext): array<string, mixed>  $callback
     */
    public function transformPivot(string $path, callable $callback): self
    {
        $this->pivotTransforms[RuleTree::assertPath($path)][] = $callback;

        return $this;
    }

    /**
     * Keep the source created/updated timestamps on every copy and pivot row.
     */
    public function preserveTimestamps(): self
    {
        $this->preserveTimestamps = true;

        return $this;
    }

    /**
     * Keep the source deleted-at value on soft-deleting copies.
     */
    public function preserveSoftDelete(): self
    {
        $this->preserveSoftDelete = true;

        return $this;
    }

    /**
     * Remove every global scope from the relation queries. This includes
     * tenant and soft-delete scopes: use it only when crossing them is intended.
     */
    public function withoutGlobalScopes(): self
    {
        $this->withoutGlobalScopes = true;

        return $this;
    }

    /**
     * Suppress Eloquent model events for the duration of the operation.
     */
    public function withoutEvents(): self
    {
        $this->withoutEvents = true;

        return $this;
    }

    public function maxDepth(int $depth): self
    {
        $this->maxDepth = $this->positive('maxDepth', $depth);

        return $this;
    }

    public function maxModels(int $limit): self
    {
        $this->maxModels = $this->positive('maxModels', $limit);

        return $this;
    }

    public function maxPivotRows(int $limit): self
    {
        $this->maxPivotRows = $this->positive('maxPivotRows', $limit);

        return $this;
    }

    /**
     * The number of parents per relation query and of rows per pivot insert.
     */
    public function chunk(int $size): self
    {
        $this->chunk = $this->positive('chunk', $size);

        return $this;
    }

    /**
     * @param  callable(ReplicationContext): void  $callback
     */
    public function beforeReplicate(callable $callback): self
    {
        return $this->on('beforeReplicate', $callback);
    }

    /**
     * @param  callable(Model, Model, ReplicationContext): void  $callback
     */
    public function beforeModel(callable $callback): self
    {
        return $this->on('beforeModel', $callback);
    }

    /**
     * @param  callable(Model, Model, ReplicationContext): void  $callback
     */
    public function afterModel(callable $callback): self
    {
        return $this->on('afterModel', $callback);
    }

    /**
     * @param  callable(ReplicationResult, ReplicationContext): void  $callback
     */
    public function afterReplicate(callable $callback): self
    {
        return $this->on('afterReplicate', $callback);
    }

    /**
     * Run once the outermost database transaction has committed.
     *
     * @param  callable(ReplicationResult): void  $callback
     */
    public function afterCommit(callable $callback): self
    {
        return $this->on('afterCommit', $callback);
    }

    /**
     * Inspect the selected graph without writing anything and without running
     * model events, lifecycle callbacks, transforms or generators.
     */
    public function dryRun(): ReplicationPlan
    {
        if ($this->executed) {
            throw BuilderAlreadyExecutedException::for(get_class($this->source));
        }

        return (new ReplicationEngine)->dryRun($this->definition());
    }

    /**
     * Validate, plan and replicate the graph in one transaction.
     */
    public function replicate(): ReplicationResult
    {
        if ($this->executed) {
            throw BuilderAlreadyExecutedException::for(get_class($this->source));
        }

        $this->executed = true;

        return (new ReplicationEngine)->replicate($this->definition());
    }

    private function definition(): Definition
    {
        $tree = new RuleTree($this->selections, get_class($this->source));
        $active = [Strategy::COPY, Strategy::REUSE];

        foreach (array_keys($this->filters) as $path) {
            $this->assertOption('filter', $path, $tree, $active);
        }

        foreach (array_keys($this->loaded) as $path) {
            $this->assertOption('usingLoaded', $path, $tree, $active);

            if (isset($this->filters[$path])) {
                throw IncompatibleRelationOptionsException::filterAndLoaded($path);
            }
        }

        foreach (array_keys($this->rules) as $path) {
            $this->assertOption('configure', $path, $tree, [Strategy::COPY]);
        }

        foreach (array_keys($this->copyPivot + $this->pivotTransforms) as $path) {
            $this->assertOption(isset($this->pivotTransforms[$path]) ? 'transformPivot' : 'copyPivot', $path, $tree, $active);
        }

        [$rootDefaults, $pathDefaults] = $this->modelDefaults();
        $rules = [];

        foreach ($tree->paths() as $path => $strategy) {
            if ($strategy !== Strategy::COPY) {
                continue;
            }

            $rule = $this->rules[$path] ?? null;
            $fallback = $pathDefaults[$path] ?? null;

            if ($rule !== null || $fallback !== null) {
                $rules[$path] = ($rule ?? new RelationRule)->over($fallback ?? new RelationRule);
            }
        }

        return new Definition(
            $this->source,
            $tree,
            $this->root->over($rootDefaults),
            $rules,
            $this->filters,
            $this->loaded,
            $this->pivotTransforms,
            $this->copyPivot,
            $this->preserveTimestamps,
            $this->preserveSoftDelete,
            $this->withoutGlobalScopes,
            $this->withoutEvents,
            $this->maxDepth,
            $this->maxModels,
            $this->maxPivotRows,
            $this->chunk,
            $this->callbacks
        );
    }

    /**
     * @return array{0: RelationRule, 1: array<string, RelationRule>}
     */
    private function modelDefaults(): array
    {
        if (! method_exists($this->source, 'deepReplicationDefaults')) {
            return [new RelationRule, []];
        }

        // The hook is protected on purpose; reflection reads it without
        // widening its visibility.
        $hook = new ReflectionMethod($this->source, 'deepReplicationDefaults');
        $hook->setAccessible(true);
        $defaults = $hook->invoke($this->source);

        if (! is_array($defaults)) {
            throw new InvalidArgumentException('deepReplicationDefaults() of ['.get_class($this->source).'] must return an array.');
        }

        $paths = [];
        $configured = $defaults['paths'] ?? [];

        if (! is_array($configured)) {
            throw new InvalidArgumentException('deepReplicationDefaults() paths must be an array.');
        }

        foreach ($configured as $path => $settings) {
            if (! is_string($path) || ! is_array($settings)) {
                throw new InvalidArgumentException('deepReplicationDefaults() paths must map relation paths to arrays of settings.');
            }

            $paths[RuleTree::assertPath($path)] = RelationRule::fromDefaults($settings, "paths.{$path}");
        }

        unset($defaults['paths']);

        return [RelationRule::fromDefaults($defaults, 'root'), $paths];
    }

    /**
     * @param  list<string>  $strategies
     */
    private function assertOption(string $method, string $path, RuleTree $tree, array $strategies): void
    {
        $node = $tree->get($path);

        if ($node === null) {
            throw InvalidRelationshipPathException::notSelected($method, $path);
        }

        if (! in_array($node->strategy, $strategies, true)) {
            throw IncompatibleRelationOptionsException::optionOnStrategy($method, $path, $node->strategy);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function selectAll(string $strategy, string $path, array $paths): self
    {
        foreach (array_merge([$path], $paths) as $candidate) {
            $this->select(RuleTree::assertPath($candidate), $strategy);
        }

        return $this;
    }

    private function select(string $path, string $strategy): void
    {
        $this->selections[] = [$path, $strategy];
    }

    private function on(string $event, callable $callback): self
    {
        $this->callbacks[$event][] = $callback;

        return $this;
    }

    /**
     * @return positive-int
     */
    private function positive(string $method, int $value): int
    {
        if ($value < 1) {
            throw new InvalidArgumentException("{$method}() expects a positive integer, {$value} given.");
        }

        return $value;
    }
}
