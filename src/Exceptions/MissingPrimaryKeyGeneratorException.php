<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class MissingPrimaryKeyGeneratorException extends DeepReplicationException
{
    public static function for(string $modelClass, ?string $path, string $keyName): self
    {
        return new self(
            "The [{$modelClass}] copy at ".self::at($path)." needs a new value for its non-incrementing primary key [{$keyName}], "
            ."but the model has no native unique id generator. Register one with generate(\"{$keyName}\", ...) on that path."
        );
    }
}
