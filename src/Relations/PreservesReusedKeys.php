<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;

/**
 * @internal
 */
trait PreservesReusedKeys
{
    /**
     * The foreign key value a reused target keeps: the parent's own stored
     * value, which already points to it (type included), or the target key.
     */
    private function preserved(GraphNode $parent, string $foreignKey, GraphNode $target, string $ownerKey): mixed
    {
        $stored = $parent->source->getAttributes()[$foreignKey] ?? null;
        $owner = $target->source->getAttribute($ownerKey);

        return $stored !== null && is_scalar($stored) && is_scalar($owner) && (string) $stored === (string) $owner ? $stored : $owner;
    }
}
