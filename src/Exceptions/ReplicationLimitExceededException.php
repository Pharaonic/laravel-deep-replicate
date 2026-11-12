<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Exceptions;

final class ReplicationLimitExceededException extends DeepReplicationException
{
    public static function depth(string $path, int $depth, int $limit): self
    {
        return new self("Relation path [{$path}] reaches depth {$depth}, above maxDepth({$limit}). Raise the limit explicitly if this depth is intended.");
    }

    public static function models(int $limit, string $path): self
    {
        return new self("The graph exceeds maxModels({$limit}) while traversing path [{$path}]. Nothing was written. Raise the limit explicitly or narrow the selection.");
    }

    public static function pivotRows(int $limit, string $path): self
    {
        return new self("The graph exceeds maxPivotRows({$limit}) while traversing path [{$path}]. Nothing was written. Raise the limit explicitly or narrow the selection.");
    }
}
