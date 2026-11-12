<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class EmptyRelationshipSelectionException extends DeepReplicationException
{
    public static function for(string $modelClass): self
    {
        return new self(
            "Deep replication of [{$modelClass}] needs at least one relation path selected as copy or reuse. "
            .'Skip-only or empty selections are rejected; use Model::replicate() to copy the model alone.'
        );
    }
}
