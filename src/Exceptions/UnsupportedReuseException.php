<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class UnsupportedReuseException extends DeepReplicationException
{
    public static function for(string $modelClass, string $path, string $relationClass): self
    {
        return new self(
            "Relation path [{$path}] on [{$modelClass}] is a [{$relationClass}] and cannot be reused: "
            .'linking its existing records to the replica would move them away from their original parent. Use copy or skip.'
        );
    }
}
