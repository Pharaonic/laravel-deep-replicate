<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingIdentityStrategyException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationLimitExceededException;
use Pharaonic\Laravel\DeepReplicate\PlanningWarning;

/**
 * The identity map, links and pivot rows of one planned operation.
 *
 * @internal
 */
final class Graph
{
    public Definition $definition;

    public GraphNode $root;

    /** @var array<string, GraphNode> in discovery order */
    public array $nodes = [];

    /** @var list<PivotLink> */
    public array $pivots = [];

    /** @var list<PlanningWarning> */
    public array $warnings = [];

    public int $maxDepthReached = 0;

    private int $copies = 0;

    private int $reuses = 0;

    public function __construct(Definition $definition)
    {
        $this->definition = $definition;
        $this->root = $this->add($definition->source, Strategy::COPY, 0, '');
    }

    /**
     * Resolve the node of an original reached through a path.
     */
    public function resolve(Model $original, string $strategy, int $depth, string $path): GraphNode
    {
        $key = IdentityMap::keyFor($original, $path);
        $node = $this->nodes[$key] ?? null;

        if ($node === null) {
            $node = $this->add($original, $strategy, $depth, $path);
        } elseif ($node->strategy !== $strategy) {
            throw ConflictingIdentityStrategyException::for(get_class($original), $node->path, $node->strategy, $path, $strategy);
        } elseif ($node->isCopy()) {
            $node->adoptRule($path, $this->definition->ruleFor($path), $this->definition->ruleFor($node->rulePath));
        }

        $this->maxDepthReached = max($this->maxDepthReached, $depth);

        return $node;
    }

    public function addPivot(PivotLink $link): void
    {
        if (count($this->pivots) >= $this->definition->maxPivotRows) {
            throw ReplicationLimitExceededException::pivotRows($this->definition->maxPivotRows, $link->target->path);
        }

        $this->pivots[] = $link;
    }

    public function warn(string $code, ?string $path, string $message): void
    {
        $this->warnings[] = new PlanningWarning($code, $path, $message);
    }

    public function copies(): int
    {
        return $this->copies;
    }

    public function reuses(): int
    {
        return $this->reuses;
    }

    /**
     * @return list<GraphNode>
     */
    public function copyNodes(): array
    {
        return array_values(array_filter($this->nodes, static function (GraphNode $node): bool {
            return $node->isCopy();
        }));
    }

    private function add(Model $original, string $strategy, int $depth, string $path): GraphNode
    {
        if (count($this->nodes) >= $this->definition->maxModels) {
            throw ReplicationLimitExceededException::models($this->definition->maxModels, $path === '' ? '(root)' : $path);
        }

        $key = IdentityMap::keyFor($original, $path);
        $node = new GraphNode($key, $original, $strategy, count($this->nodes), $depth, $path);
        $this->nodes[$key] = $node;

        if ($node->isCopy()) {
            $this->copies++;
        } else {
            $this->reuses++;
        }

        return $node;
    }
}
