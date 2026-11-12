<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class ConflictingRelationshipRuleException extends DeepReplicationException
{
    public static function for(string $path, string $first, string $second): self
    {
        return new self(
            "Relation path [{$path}] is selected as both \"{$first}\" and \"{$second}\". A path takes exactly one strategy."
        );
    }
}
