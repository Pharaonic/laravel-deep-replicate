<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingNodeConfigurationException;
use Pharaonic\Laravel\DeepReplicate\RelationRule;

/**
 * One distinct original record of the graph.
 *
 * @internal
 */
final class GraphNode
{
    public const DISCOVERED = 'discovered';

    public const PREPARED = 'prepared';

    public const INSERTED = 'inserted';

    public const LINKED = 'linked';

    public string $key;

    public Model $source;

    public string $strategy;

    /** Discovery order, used for every deterministic ordering. */
    public int $order;

    public int $depth;

    /** The path through which the node was first reached; '' for the root. */
    public string $path;

    /** The path whose rule configures the copy. */
    public string $rulePath;

    public string $state = self::DISCOVERED;

    public ?Model $copy = null;

    /** @var array<string, LinkAssignment> */
    public array $links = [];

    /** @var array<string, string> managed column => path that manages it */
    public array $managed = [];

    public function __construct(string $key, Model $source, string $strategy, int $order, int $depth, string $path)
    {
        $this->key = $key;
        $this->source = $source;
        $this->strategy = $strategy;
        $this->order = $order;
        $this->depth = $depth;
        $this->path = $path;
        $this->rulePath = $path;
    }

    public function isCopy(): bool
    {
        return $this->strategy === Strategy::COPY;
    }

    public function assign(LinkAssignment $link): void
    {
        $existing = $this->links[$link->column] ?? null;

        if ($existing !== null && ! $existing->sameAs($link)) {
            throw ConflictingNodeConfigurationException::link(get_class($this->source), $link->column, $existing->path, $link->path);
        }

        $this->links[$link->column] = $existing ?? $link;
        $this->manage($link->column, $link->path);
    }

    public function manage(string $column, string $path): void
    {
        $this->managed[$column] = $this->managed[$column] ?? $path;
    }

    /**
     * Adopt the rule of another path reaching this node, when compatible.
     */
    public function adoptRule(string $path, RelationRule $candidate, RelationRule $current): void
    {
        if ($candidate->isEmpty() || $candidate->sameAs($current)) {
            return;
        }

        if (! $current->isEmpty()) {
            throw ConflictingNodeConfigurationException::configuration(get_class($this->source), $this->rulePath, $path);
        }

        $this->rulePath = $path;
    }
}
