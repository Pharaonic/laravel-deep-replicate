<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\AfterCommitUnavailableException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\CrossConnectionReplicationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ProtectedRelationshipAttributeException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationCancelledException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnresolvableForeignKeyCycleException;
use Pharaonic\Laravel\DeepReplicate\Relations\Handlers;
use Pharaonic\Laravel\DeepReplicate\ReplicationContext;
use Pharaonic\Laravel\DeepReplicate\ReplicationMap;
use Pharaonic\Laravel\DeepReplicate\ReplicationPlan;
use Pharaonic\Laravel\DeepReplicate\ReplicationResult;
use Pharaonic\Laravel\DeepReplicate\ReplicationStats;
use RuntimeException;

/**
 * Runs one operation: a read-only plan, or a plan and its execution in one
 * transaction on the source's connection.
 *
 * @internal
 */
final class ReplicationEngine
{
    private RelationResolver $resolver;

    private GraphPlanner $planner;

    public function __construct()
    {
        $this->resolver = new RelationResolver;
        $this->planner = new GraphPlanner($this->resolver, new Handlers);
    }

    public function dryRun(Definition $definition): ReplicationPlan
    {
        $started = microtime(true);

        // Model events (retrieved included) never fire during a dry run.
        $graph = EventGate::quietly(true, function () use ($definition): Graph {
            return $this->planner->plan($definition);
        });

        return new ReplicationPlan(
            $definition->tree->paths(),
            $graph->warnings,
            $this->stats($graph, count($graph->pivots), $started)
        );
    }

    public function replicate(Definition $definition): ReplicationResult
    {
        $started = microtime(true);
        $connection = $definition->source->getConnection();
        $afterCommit = $definition->callbacks('afterCommit');
        $outermost = $connection->transactionLevel() === 0;

        if ($afterCommit !== [] && ! $outermost && ! $this->supportsAfterCommit($connection)) {
            throw AfterCommitUnavailableException::for($connection->getName());
        }

        /** @var ReplicationResult $result */
        $result = $connection->transaction(function () use ($definition, $connection, $afterCommit, $outermost, $started): ReplicationResult {
            $result = EventGate::quietly($definition->withoutEvents, function () use ($definition, $started): ReplicationResult {
                return $this->execute($definition, $started);
            });

            if ($afterCommit !== [] && ! $outermost) {
                $this->deferToOutermostCommit($connection, $afterCommit, $result);
            }

            return $result;
        }, 1);

        if ($afterCommit !== [] && $outermost) {
            foreach ($afterCommit as $callback) {
                $callback($result);
            }
        }

        return $result;
    }

    private function execute(Definition $definition, float $started): ReplicationResult
    {
        // Plan again inside the transaction: a dry run's snapshot is never trusted.
        $graph = $this->planner->plan($definition);
        $connectionName = (string) $definition->source->getConnection()->getName();
        $context = function (?string $path, int $depth) use ($graph, $connectionName): ReplicationContext {
            return new ReplicationContext($graph->definition->source, $graph->root->copy, $path === '' ? null : $path, $depth, $connectionName);
        };

        foreach ($definition->callbacks('beforeReplicate') as $callback) {
            $callback($context(null, 0));
        }

        $copier = new AttributeCopier;
        $nodes = $graph->copyNodes();

        foreach ($nodes as $node) {
            $copier->prepare($node, $definition, $context($node->path, $node->depth));
        }

        foreach ((new DependencyResolver)->order($nodes) as $node) {
            $this->insert($graph, $node, $context($node->path, $node->depth));
        }

        $this->resolveDeferredLinks($nodes);

        $pivots = (new PivotReplicator($this->resolver))->insert($graph, $context);

        $copies = [];
        $reused = [];

        foreach ($graph->nodes as $node) {
            if ($node->isCopy()) {
                /** @var Model $copy */
                $copy = $node->copy;
                $copy->setRelations([]);
                $node->state = GraphNode::LINKED;
                $copies[$node->key] = $copy;
            } else {
                $reused[$node->key] = $node->source;
            }
        }

        foreach ($nodes as $node) {
            foreach ($definition->callbacks('afterModel') as $callback) {
                $callback($node->source, $node->copy, $context($node->path, $node->depth));
            }
        }

        /** @var Model $root */
        $root = $graph->root->copy;
        $result = new ReplicationResult($root, new ReplicationMap($copies, $reused), $this->stats($graph, $pivots, $started));

        foreach ($definition->callbacks('afterReplicate') as $callback) {
            $callback($result, $context(null, 0));
        }

        return $result;
    }

    private function insert(Graph $graph, GraphNode $node, ReplicationContext $context): void
    {
        $definition = $graph->definition;
        /** @var Model $copy */
        $copy = $node->copy;
        $attributes = $copy->getAttributes();
        $deferred = [];

        foreach ($node->links as $link) {
            if ($link->target === null) {
                continue;
            }

            if ($link->deferred) {
                $attributes[$link->column] = null;
                $deferred[] = $link->column;
            } else {
                /** @var Model $targetCopy */
                $targetCopy = $link->target->copy;
                $attributes[$link->column] = $targetCopy->getAttribute($link->targetAttribute);
            }
        }

        $copy->setRawAttributes($attributes);

        $path = $node->path === '' ? null : $node->path;
        $protected = array_keys($node->managed);
        $protected[] = $copy->getKeyName();
        $snapshot = array_intersect_key($copy->getAttributes(), array_flip($protected));

        foreach ($definition->callbacks('beforeModel') as $callback) {
            $callback($node->source, $copy, $context);
        }

        if ($copy->getConnection() !== $definition->source->getConnection()) {
            throw CrossConnectionReplicationException::for(get_class($copy), $path, $definition->source->getConnection()->getName(), $copy->getConnection()->getName());
        }

        $after = array_intersect_key($copy->getAttributes(), array_flip($protected));

        foreach ($protected as $column) {
            if (AttributeValues::changed($column, $snapshot, $after)) {
                throw ProtectedRelationshipAttributeException::for(get_class($copy), $path, $column, 'beforeModel()');
            }
        }

        $timestamps = $copy->timestamps;

        if ($definition->preservesTimestamps($node->rulePath)) {
            $copy->timestamps = false;
        }

        try {
            $saved = EventGate::quietly($definition->suppressesEventsFor($node->rulePath), static function () use ($copy): bool {
                return $copy->save();
            });
        } catch (QueryException $exception) {
            if ($deferred !== [] && $this->rejectsNull($exception, $deferred)) {
                throw UnresolvableForeignKeyCycleException::for(get_class($copy), $path, $deferred, $exception);
            }

            throw $exception;
        } finally {
            $copy->timestamps = $timestamps;
        }

        if ($saved === false) {
            throw ReplicationCancelledException::for(get_class($copy), $path);
        }

        $node->state = GraphNode::INSERTED;
    }

    /**
     * @param  list<GraphNode>  $nodes
     */
    private function resolveDeferredLinks(array $nodes): void
    {
        foreach ($nodes as $node) {
            $values = [];

            foreach ($node->links as $link) {
                if ($link->deferred && $link->target !== null) {
                    /** @var Model $targetCopy */
                    $targetCopy = $link->target->copy;
                    $values[$link->column] = $targetCopy->getAttribute($link->targetAttribute);
                }
            }

            if ($values === []) {
                continue;
            }

            /** @var Model $copy */
            $copy = $node->copy;
            $copy->getConnection()->table($copy->getTable())->where($copy->getKeyName(), $copy->getKey())->update($values);

            foreach ($values as $column => $value) {
                $copy->setAttribute($column, $value);
                $copy->syncOriginalAttribute($column);
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function rejectsNull(QueryException $exception, array $columns): bool
    {
        $message = strtolower($exception->getMessage());

        if (strpos($message, 'null') === false) {
            return false;
        }

        foreach ($columns as $column) {
            if (strpos($message, strtolower($column)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<callable>  $callbacks
     */
    private function deferToOutermostCommit(Connection $connection, array $callbacks, ReplicationResult $result): void
    {
        foreach ($callbacks as $callback) {
            try {
                $this->registerAfterCommit($connection, static function () use ($callback, $result): void {
                    $callback($result);
                });
            } catch (RuntimeException $exception) {
                // Laravel throws when the connection has no transactions manager.
                throw AfterCommitUnavailableException::for($connection->getName());
            }
        }
    }

    /**
     * Connection::afterCommit() exists from Laravel 8.x; it runs the callback
     * once the outermost transaction of the connection commits.
     */
    private function supportsAfterCommit(object $connection): bool
    {
        return method_exists($connection, 'afterCommit');
    }

    private function registerAfterCommit(object $connection, Closure $callback): void
    {
        if (! method_exists($connection, 'afterCommit')) {
            throw new RuntimeException('Connection::afterCommit() is not available.');
        }

        $connection->afterCommit($callback);
    }

    private function stats(Graph $graph, int $pivots, float $started): ReplicationStats
    {
        return new ReplicationStats(
            $graph->copies(),
            $graph->reuses(),
            $graph->definition->tree->countSkipped(),
            $pivots,
            $graph->maxDepthReached,
            round((microtime(true) - $started) * 1000, 3)
        );
    }
}
