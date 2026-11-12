<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

/**
 * Counts for one replication (actual) or one plan (projected).
 *
 * Shared records are counted once: a record reached through several edges is
 * one copied or reused model. Pivot rows are counted per inserted row.
 */
final class ReplicationStats
{
    private int $copiedModels;

    private int $reusedModels;

    private int $skippedRelationships;

    private int $pivotRowsCreated;

    private int $maxDepthReached;

    private float $durationMs;

    /**
     * @internal
     */
    public function __construct(
        int $copiedModels,
        int $reusedModels,
        int $skippedRelationships,
        int $pivotRowsCreated,
        int $maxDepthReached,
        float $durationMs
    ) {
        $this->copiedModels = $copiedModels;
        $this->reusedModels = $reusedModels;
        $this->skippedRelationships = $skippedRelationships;
        $this->pivotRowsCreated = $pivotRowsCreated;
        $this->maxDepthReached = $maxDepthReached;
        $this->durationMs = $durationMs;
    }

    /**
     * New models created, the root included.
     */
    public function copiedModels(): int
    {
        return $this->copiedModels;
    }

    /**
     * Distinct existing models linked to the replica without being copied.
     */
    public function reusedModels(): int
    {
        return $this->reusedModels;
    }

    /**
     * Relation paths selected as skip.
     */
    public function skippedRelationships(): int
    {
        return $this->skippedRelationships;
    }

    /**
     * Pivot rows inserted (projected for a plan).
     */
    public function pivotRowsCreated(): int
    {
        return $this->pivotRowsCreated;
    }

    /**
     * The deepest relation level at which a record was reached; 0 when only
     * the root was copied.
     */
    public function maxDepthReached(): int
    {
        return $this->maxDepthReached;
    }

    /**
     * Wall-clock time of the operation (or of planning, for a plan).
     */
    public function durationMs(): float
    {
        return $this->durationMs;
    }

    /**
     * @return array{copiedModels: int, reusedModels: int, skippedRelationships: int, pivotRowsCreated: int, maxDepthReached: int, durationMs: float}
     */
    public function toArray(): array
    {
        return [
            'copiedModels' => $this->copiedModels,
            'reusedModels' => $this->reusedModels,
            'skippedRelationships' => $this->skippedRelationships,
            'pivotRowsCreated' => $this->pivotRowsCreated,
            'maxDepthReached' => $this->maxDepthReached,
            'durationMs' => $this->durationMs,
        ];
    }
}
