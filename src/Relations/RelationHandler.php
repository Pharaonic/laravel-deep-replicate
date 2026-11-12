<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\DeepReplicate\Engine\Graph;
use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;

/**
 * How one family of Eloquent relations is read and linked.
 *
 * This is the internal extension boundary of the engine: every supported
 * relation class has exactly one handler, and a relation without one is
 * rejected. It is not a public extension point in v1.
 *
 * @internal
 */
interface RelationHandler
{
    /**
     * Whether this handler replicates the given relation instance (matched by exact class).
     *
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function supports(Relation $relation): bool;

    public function supportsReuse(): bool;

    public function isManyToMany(): bool;

    /**
     * The related model when it is known without data; null when it is
     * decided per record (MorphTo).
     *
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function staticRelated(Relation $relation): ?Model;

    /**
     * Columns of the parent copy that this relation manages.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     * @return list<string>
     */
    public function parentColumns(Relation $relation): array;

    /**
     * Read the targets of every parent with one query (per morph type for MorphTo).
     *
     * @param  list<Model>  $parents  throwaway copies of the source parents
     * @param  list<callable>  $filters
     * @param  Relation<Model, Model, mixed>  $relation
     * @return list<list<array{0: Model, 1: Model|null}>> per parent: [target, pivot]
     */
    public function fetch(Relation $relation, array $parents, string $name, string $path, array $filters, bool $withoutGlobalScopes, Graph $graph): array;

    /**
     * Record the edge between a parent copy and a target node.
     *
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function connect(Graph $graph, GraphNode $parent, GraphNode $target, Relation $relation, ?Model $pivot, string $path): void;
}
