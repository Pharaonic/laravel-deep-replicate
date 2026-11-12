<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class BuilderAlreadyExecutedException extends DeepReplicationException
{
    public static function for(string $modelClass): self
    {
        return new self("This deep replication builder for [{$modelClass}] has already run replicate(). Builders are single-use; create a new one.");
    }
}
