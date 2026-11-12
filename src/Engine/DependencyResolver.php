<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use SplPriorityQueue;

/**
 * Orders copy inserts so every copy is inserted after the copies its linking
 * columns point to. A cycle is broken at its earliest-discovered node: those
 * links are inserted as NULL and updated once their targets exist.
 *
 * @internal
 */
final class DependencyResolver
{
    /**
     * @param  list<GraphNode>  $nodes  copy nodes in discovery order
     * @return list<GraphNode>
     */
    public function order(array $nodes): array
    {
        $pending = [];
        $dependents = [];

        foreach ($nodes as $node) {
            $pending[$node->key] = 0;

            foreach ($node->links as $link) {
                if ($link->target === null) {
                    continue;
                }

                if ($link->target === $node) {
                    $link->deferred = true;

                    continue;
                }

                $pending[$node->key]++;
                $dependents[$link->target->key][] = $node;
            }
        }

        /** @var SplPriorityQueue<int, GraphNode> $ready */
        $ready = new SplPriorityQueue;

        foreach ($nodes as $node) {
            if ($pending[$node->key] === 0) {
                $ready->insert($node, -$node->order);
            }
        }

        $ordered = [];
        $done = [];
        $cursor = 0;
        $total = count($nodes);

        while (count($ordered) < $total) {
            if ($ready->isEmpty()) {
                while (isset($done[$nodes[$cursor]->key]) || $pending[$nodes[$cursor]->key] === 0) {
                    $cursor++;
                }

                $breaker = $nodes[$cursor];

                foreach ($breaker->links as $link) {
                    if ($link->target !== null && ! isset($done[$link->target->key])) {
                        $link->deferred = true;
                    }
                }

                $pending[$breaker->key] = 0;
                $ready->insert($breaker, -$breaker->order);
            }

            /** @var GraphNode $node */
            $node = $ready->extract();

            if (isset($done[$node->key])) {
                continue;
            }

            $done[$node->key] = true;
            $ordered[] = $node;

            foreach ($dependents[$node->key] ?? [] as $dependent) {
                if (! isset($done[$dependent->key]) && $pending[$dependent->key] > 0) {
                    $pending[$dependent->key]--;

                    if ($pending[$dependent->key] === 0) {
                        $ready->insert($dependent, -$dependent->order);
                    }
                }
            }
        }

        return $ordered;
    }
}
