<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\RelationRule;

/**
 * An immutable snapshot of a builder's configuration, validated and resolved
 * against the model defaults.
 *
 * @internal
 */
final class Definition
{
    public Model $source;

    public RuleTree $tree;

    public RelationRule $rootRule;

    /** @var array<string, RelationRule> effective rules of copy paths */
    public array $rules;

    /** @var array<string, list<callable>> */
    public array $filters;

    /** @var array<string, true> */
    public array $loaded;

    /** @var array<string, list<callable>> */
    public array $pivotTransforms;

    /** @var array<string, true> */
    public array $copyPivot;

    public bool $preserveTimestamps;

    public bool $preserveSoftDelete;

    public bool $withoutGlobalScopes;

    public bool $withoutEvents;

    public int $maxDepth;

    public int $maxModels;

    public int $maxPivotRows;

    /** @var positive-int */
    public int $chunk;

    /** @var array<string, list<callable>> */
    public array $callbacks;

    /**
     * @param  array<string, RelationRule>  $rules
     * @param  array<string, list<callable>>  $filters
     * @param  array<string, true>  $loaded
     * @param  array<string, list<callable>>  $pivotTransforms
     * @param  array<string, true>  $copyPivot
     * @param  positive-int  $chunk
     * @param  array<string, list<callable>>  $callbacks
     */
    public function __construct(
        Model $source,
        RuleTree $tree,
        RelationRule $rootRule,
        array $rules,
        array $filters,
        array $loaded,
        array $pivotTransforms,
        array $copyPivot,
        bool $preserveTimestamps,
        bool $preserveSoftDelete,
        bool $withoutGlobalScopes,
        bool $withoutEvents,
        int $maxDepth,
        int $maxModels,
        int $maxPivotRows,
        int $chunk,
        array $callbacks
    ) {
        $this->source = $source;
        $this->tree = $tree;
        $this->rootRule = $rootRule;
        $this->rules = $rules;
        $this->filters = $filters;
        $this->loaded = $loaded;
        $this->pivotTransforms = $pivotTransforms;
        $this->copyPivot = $copyPivot;
        $this->preserveTimestamps = $preserveTimestamps;
        $this->preserveSoftDelete = $preserveSoftDelete;
        $this->withoutGlobalScopes = $withoutGlobalScopes;
        $this->withoutEvents = $withoutEvents;
        $this->maxDepth = $maxDepth;
        $this->maxModels = $maxModels;
        $this->maxPivotRows = $maxPivotRows;
        $this->chunk = $chunk;
        $this->callbacks = $callbacks;
    }

    public function ruleFor(string $path): RelationRule
    {
        return $path === '' ? $this->rootRule : ($this->rules[$path] ?? new RelationRule);
    }

    public function preservesTimestamps(string $path): bool
    {
        return $this->ruleFor($path)->timestampsPreserved() ?? $this->preserveTimestamps;
    }

    public function suppressesEventsFor(string $path): bool
    {
        return $this->ruleFor($path)->eventsSuppressed() ?? false;
    }

    /**
     * @return list<callable>
     */
    public function callbacks(string $name): array
    {
        return $this->callbacks[$name] ?? [];
    }
}
