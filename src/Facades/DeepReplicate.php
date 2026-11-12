<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Facades;

use Illuminate\Support\Facades\Facade;
use Pharaonic\Laravel\DeepReplicate\DeepReplicator;

/**
 * @method static \Pharaonic\Laravel\DeepReplicate\ReplicationBuilder for(\Illuminate\Database\Eloquent\Model $source, array<int|string, mixed>|string|null $relations = null)
 *
 * @see DeepReplicator
 */
final class DeepReplicate extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DeepReplicator::class;
    }
}
