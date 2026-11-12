<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

/**
 * A read-only inspection of the selected graph, made by dryRun().
 *
 * Counts are a snapshot of the database at planning time; replicate() plans
 * again inside its transaction and may see different data.
 */
final class ReplicationPlan
{
    /** @var array<string, string> */
    private array $relationships;

    /** @var list<PlanningWarning> */
    private array $warnings;

    private ReplicationStats $stats;

    /**
     * @internal
     *
     * @param  array<string, string>  $relationships
     * @param  list<PlanningWarning>  $warnings
     */
    public function __construct(array $relationships, array $warnings, ReplicationStats $stats)
    {
        $this->relationships = $relationships;
        $this->warnings = $warnings;
        $this->stats = $stats;
    }

    /**
     * Projected counts.
     */
    public function stats(): ReplicationStats
    {
        return $this->stats;
    }

    /**
     * Every canonical selected path with its strategy, implied parents
     * included, e.g. ['items' => 'copy', 'items.product' => 'reuse'].
     *
     * @return array<string, string>
     */
    public function relationships(): array
    {
        return $this->relationships;
    }

    /**
     * @return list<PlanningWarning>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Distinct models in the graph: copies (root included) plus reused records.
     */
    public function estimatedModels(): int
    {
        return $this->stats->copiedModels() + $this->stats->reusedModels();
    }

    public function estimatedPivotRows(): int
    {
        return $this->stats->pivotRowsCreated();
    }
}
