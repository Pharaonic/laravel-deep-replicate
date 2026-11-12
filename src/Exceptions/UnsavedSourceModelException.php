<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class UnsavedSourceModelException extends DeepReplicationException
{
    public static function for(string $modelClass, ?string $path = null): self
    {
        return new self(
            "A [{$modelClass}] at ".self::at($path).' is not persisted or has no primary key value; only persisted models can be deep replicated.'
        );
    }
}
