<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class IncompatibleRelationOptionsException extends DeepReplicationException
{
    public static function nestedUnder(string $parent, string $parentStrategy, string $path, string $strategy): self
    {
        $reason = $parentStrategy === 'skip'
            ? 'a skipped relation is never traversed'
            : 'a reused relation is a traversal terminal: its records are never modified or traversed';

        return new self(
            "Relation path [{$path}] is selected as \"{$strategy}\" under [{$parent}], which is selected as \"{$parentStrategy}\"; {$reason}."
        );
    }

    public static function filterAndLoaded(string $path): self
    {
        return new self("filter() and usingLoaded() cannot both target relation path [{$path}]: a loaded relation is never queried again.");
    }

    public static function optionOnStrategy(string $method, string $path, string $strategy): self
    {
        return new self("{$method}() cannot target relation path [{$path}], which is selected as \"{$strategy}\".");
    }

    public static function pivotOption(string $method, string $path, string $relationClass): self
    {
        return new self("{$method}() needs a many-to-many relation, but relation path [{$path}] is a [{$relationClass}].");
    }
}
