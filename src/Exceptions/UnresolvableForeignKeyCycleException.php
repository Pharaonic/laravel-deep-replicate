<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

use Throwable;

final class UnresolvableForeignKeyCycleException extends DeepReplicationException
{
    /**
     * @param  list<string>  $columns
     */
    public static function for(string $modelClass, ?string $path, array $columns, ?Throwable $previous = null): self
    {
        $list = implode(', ', $columns);

        return new self(
            "The selected graph has a foreign key cycle through [{$modelClass}] at ".self::at($path)
            .". Breaking it needs [{$list}] to be inserted as NULL and updated afterwards, but the database rejected the NULL value. "
            .'Make one column of the cycle nullable, or do not select both directions of the cycle.',
            0,
            $previous
        );
    }
}
