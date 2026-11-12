<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Engine\IdentityMap;

/**
 * Original-to-copy mapping of one replication.
 *
 * Identity keys read "{connection}|{model class}|{key type}:{key}", for
 * example "sqlite|App\Models\Item|integer:42".
 */
final class ReplicationMap
{
    /** @var array<string, Model> */
    private array $copies;

    /** @var array<string, Model> */
    private array $reused;

    /**
     * @internal
     *
     * @param  array<string, Model>  $copies
     * @param  array<string, Model>  $reused
     */
    public function __construct(array $copies, array $reused)
    {
        $this->copies = $copies;
        $this->reused = $reused;
    }

    /**
     * The copy made from this original, or null when it was not copied
     * (reused, skipped, or not part of the graph).
     */
    public function get(Model $original): ?Model
    {
        $key = IdentityMap::tryKeyFor($original);

        return $key === null ? null : ($this->copies[$key] ?? null);
    }

    /**
     * Whether this original was copied.
     */
    public function has(Model $original): bool
    {
        return $this->get($original) !== null;
    }

    /**
     * Whether this original was reused (linked without being copied).
     */
    public function isReused(Model $original): bool
    {
        $key = IdentityMap::tryKeyFor($original);

        return $key !== null && isset($this->reused[$key]);
    }

    /**
     * Every copy keyed by its original's identity key, in traversal order.
     *
     * @return array<string, Model>
     */
    public function all(): array
    {
        return $this->copies;
    }

    /**
     * Every reused original keyed by its identity key, in traversal order.
     *
     * @return array<string, Model>
     */
    public function reused(): array
    {
        return $this->reused;
    }
}
