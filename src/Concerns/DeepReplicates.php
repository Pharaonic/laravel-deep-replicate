<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Concerns;

use Illuminate\Container\Container;
use Pharaonic\Laravel\DeepReplicate\DeepReplicator;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;
use Pharaonic\Laravel\DeepReplicate\ReplicationBuilder;

/**
 * Adds deepReplicate() to an Eloquent model. Laravel's replicate() is left
 * untouched.
 */
trait DeepReplicates
{
    /**
     * Start a deep replication of this model. At least one relation path is
     * required: positional paths are copied, an array takes relations() rules.
     *
     * @param  string|array<int|string, mixed>  $relations
     */
    public function deepReplicate(string|array $relations, string ...$paths): ReplicationBuilder
    {
        if ($relations === []) {
            throw EmptyRelationshipSelectionException::for(static::class);
        }

        /** @var DeepReplicator $replicator */
        $replicator = Container::getInstance()->make(DeepReplicator::class);
        $builder = $replicator->for($this, $relations);

        foreach ($paths as $path) {
            $builder->copy($path);
        }

        return $builder;
    }

    /**
     * Fallback configuration for paths the caller selects. It never selects a
     * path or changes a strategy.
     *
     * Keys: exclude, with, generate, transform, without_events and
     * preserve_timestamps for the root copy, and "paths" mapping a relation
     * path to the same keys.
     *
     * @return array<string, mixed>
     */
    protected function deepReplicationDefaults(): array
    {
        return [];
    }
}
