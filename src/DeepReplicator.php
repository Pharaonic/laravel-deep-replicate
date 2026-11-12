<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;

/**
 * The entry point behind the DeepReplicate facade and the model trait.
 *
 * It holds no state: every call returns a new, independent builder.
 */
final class DeepReplicator
{
    /**
     * Start a deep replication of a persisted model.
     *
     * @param  array<int|string, mixed>|string|null  $relations  a path to copy, or a relations() array
     */
    public function for(Model $source, array|string|null $relations = null): ReplicationBuilder
    {
        $builder = new ReplicationBuilder($source);

        if ($relations === null) {
            return $builder;
        }

        if (is_string($relations)) {
            return $builder->copy($relations);
        }

        if ($relations === []) {
            throw EmptyRelationshipSelectionException::for(get_class($source));
        }

        return $builder->relations($relations);
    }
}
