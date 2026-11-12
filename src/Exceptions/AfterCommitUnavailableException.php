<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class AfterCommitUnavailableException extends DeepReplicationException
{
    public static function for(?string $connection): self
    {
        $connection = $connection ?? 'unnamed';

        return new self(
            "afterCommit() callbacks cannot be guaranteed: the replication runs inside a transaction opened by the caller on connection [{$connection}], "
            .'and this Laravel version cannot run a callback after that outermost transaction commits. Nothing was written. '
            .'Replicate outside the transaction, or dispatch the work yourself after committing.'
        );
    }
}
