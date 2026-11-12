<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class ConflictingIdentityStrategyException extends DeepReplicationException
{
    public static function for(string $modelClass, string $firstPath, string $first, string $secondPath, string $second): self
    {
        return new self(
            "The same [{$modelClass}] record is reached as \"{$first}\" through ".self::at($firstPath)
            ." and as \"{$second}\" through ".self::at($secondPath).'. One record is either copied or reused within an operation, never both.'
        );
    }
}
