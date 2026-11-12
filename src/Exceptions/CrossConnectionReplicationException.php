<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class CrossConnectionReplicationException extends DeepReplicationException
{
    public static function for(string $modelClass, ?string $path, ?string $expected, ?string $actual): self
    {
        $expected = $expected ?? 'unnamed';
        $actual = $actual ?? 'unnamed';

        return new self(
            "A [{$modelClass}] at ".self::at($path)." uses connection [{$actual}], but the replication runs in one transaction on connection [{$expected}]. "
            .'Replicating across connections is not supported.'
        );
    }
}
