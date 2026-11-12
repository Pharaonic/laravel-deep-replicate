<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class InvalidRelationshipPathException extends DeepReplicationException
{
    public static function syntax(mixed $path): self
    {
        $shown = is_string($path) ? "[{$path}]" : 'of type ['.get_debug_type($path).']';

        return new self(
            "Relation path {$shown} is invalid. A path is a non-empty, dot-separated chain of relation method names, such as \"items.options\"."
        );
    }

    public static function notARelation(string $modelClass, string $path, string $reason): self
    {
        return new self("Relation path [{$path}] cannot be resolved on [{$modelClass}]: {$reason}.");
    }

    public static function unknownMorphType(string $modelClass, string $path, string $type): self
    {
        return new self(
            "Relation path [{$path}] on [{$modelClass}] points to morph type [{$type}], which is neither a morph map alias nor an Eloquent model class."
        );
    }

    public static function notSelected(string $method, string $path): self
    {
        return new self("{$method}() targets relation path [{$path}], which is not selected. Select it with copy() or reuse() first.");
    }
}
