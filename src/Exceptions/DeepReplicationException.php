<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

use RuntimeException;

/**
 * Base class for every failure raised by the package.
 *
 * Catch it to handle any deep replication failure. Messages name the model
 * class and the relation path involved and never include attribute values.
 */
abstract class DeepReplicationException extends RuntimeException
{
    protected static function at(?string $path): string
    {
        return $path === null || $path === '' ? 'the root model' : "path [{$path}]";
    }
}
