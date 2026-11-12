<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class UnsupportedRelationshipException extends DeepReplicationException
{
    public static function for(string $modelClass, string $path, string $relationClass): self
    {
        $hint = is_a($relationClass, \Illuminate\Database\Eloquent\Relations\HasManyThrough::class, true)
            ? ' Through relations are not replicated; select the intermediate relations explicitly instead.'
            : ' Only HasOne, HasMany, BelongsTo, BelongsToMany, MorphOne, MorphMany, MorphTo, MorphToMany and MorphedByMany are supported.';

        return new self("Relation path [{$path}] on [{$modelClass}] is a [{$relationClass}], which cannot be copied or reused.{$hint} It may still be skipped.");
    }
}
