<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class ReplicationCancelledException extends DeepReplicationException
{
    public static function for(string $modelClass, ?string $path): self
    {
        return new self(
            "A model event listener cancelled the insert of the [{$modelClass}] copy at ".self::at($path)
            .'. The whole replication was rolled back, because a partial copy is never kept.'
        );
    }
}
