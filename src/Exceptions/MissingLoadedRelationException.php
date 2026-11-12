<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class MissingLoadedRelationException extends DeepReplicationException
{
    public static function for(string $modelClass, string $path): self
    {
        return new self(
            "Relation path [{$path}] uses usingLoaded(), but a [{$modelClass}] source model does not have that relation loaded. "
            .'Load it on every source model first; it is never loaded implicitly.'
        );
    }
}
