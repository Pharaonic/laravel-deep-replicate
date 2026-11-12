<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class ConflictingNodeConfigurationException extends DeepReplicationException
{
    public static function configuration(string $modelClass, string $firstPath, string $secondPath): self
    {
        return new self(
            "The same [{$modelClass}] record is copied through ".self::at($firstPath).' and '.self::at($secondPath)
            .' with different attribute configurations. It is copied once, so its configuration must be identical or set on one path only.'
        );
    }

    public static function link(string $modelClass, string $column, string $firstPath, string $secondPath): self
    {
        return new self(
            "The [{$modelClass}] copy would receive two different values for its linking column [{$column}] through "
            .self::at($firstPath).' and '.self::at($secondPath).'. Select only one of these relations.'
        );
    }
}
