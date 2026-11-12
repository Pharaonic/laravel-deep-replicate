<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class InvalidRelationshipStrategyException extends DeepReplicationException
{
    public static function for(string $path, mixed $strategy): self
    {
        $shown = is_string($strategy) ? "[{$strategy}]" : 'of type ['.get_debug_type($strategy).']';

        return new self("Strategy {$shown} for relation path [{$path}] is invalid. Use \"copy\", \"reuse\" or \"skip\".");
    }
}
