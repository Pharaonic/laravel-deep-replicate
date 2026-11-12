<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

use Illuminate\Database\Eloquent\Model;

/**
 * What a callback is running for: the operation's root and the current path.
 */
final class ReplicationContext
{
    private Model $root;

    private ?Model $replica;

    private ?string $path;

    private int $depth;

    private string $connectionName;

    /**
     * @internal
     */
    public function __construct(Model $root, ?Model $replica, ?string $path, int $depth, string $connectionName)
    {
        $this->root = $root;
        $this->replica = $replica;
        $this->path = $path;
        $this->depth = $depth;
        $this->connectionName = $connectionName;
    }

    /**
     * The original root model being replicated.
     */
    public function root(): Model
    {
        return $this->root;
    }

    /**
     * The new root model, or null before it has been inserted.
     */
    public function replica(): ?Model
    {
        return $this->replica;
    }

    /**
     * The relation path of the current model; null for the root and for
     * operation-level callbacks.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * The depth of the current model: 0 for the root, 1 for direct relations.
     */
    public function depth(): int
    {
        return $this->depth;
    }

    /**
     * The name of the connection the whole operation runs on.
     */
    public function connectionName(): string
    {
        return $this->connectionName;
    }
}
