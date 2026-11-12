<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class ProtectedRelationshipAttributeException extends DeepReplicationException
{
    public static function for(string $modelClass, ?string $path, string $attribute, string $operation): self
    {
        return new self(
            "{$operation} cannot change attribute [{$attribute}] of the [{$modelClass}] copy at ".self::at($path)
            .': it is a primary key or a linking key managed by a selected relation.'
        );
    }

    public static function pivot(string $path, string $column): self
    {
        return new self(
            "transformPivot() cannot set pivot column [{$column}] at path [{$path}]: it is a linking key, morph type or surrogate key managed by the package."
        );
    }
}
