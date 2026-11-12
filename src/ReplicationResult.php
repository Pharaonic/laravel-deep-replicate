<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

use Illuminate\Database\Eloquent\Model;

/**
 * The outcome of a committed replication.
 */
final class ReplicationResult
{
    private Model $model;

    private ReplicationMap $map;

    private ReplicationStats $stats;

    /**
     * @internal
     */
    public function __construct(Model $model, ReplicationMap $map, ReplicationStats $stats)
    {
        $this->model = $model;
        $this->map = $map;
        $this->stats = $stats;
    }

    /**
     * The new, persisted root model. Its relations are not loaded.
     */
    public function model(): Model
    {
        return $this->model;
    }

    public function map(): ReplicationMap
    {
        return $this->map;
    }

    public function stats(): ReplicationStats
    {
        return $this->stats;
    }
}
