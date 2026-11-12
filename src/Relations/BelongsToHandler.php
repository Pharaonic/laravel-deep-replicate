<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\DeepReplicate\Engine\Graph;
use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;
use Pharaonic\Laravel\DeepReplicate\Engine\LinkAssignment;

/**
 * BelongsTo: the parent holds the foreign key. A copied target is inserted
 * first and the parent copy points to it; a reused target keeps its key.
 *
 * @internal
 */
final class BelongsToHandler implements RelationHandler
{
    use EagerFetching;
    use PreservesReusedKeys;

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function supports(Relation $relation): bool
    {
        return get_class($relation) === BelongsTo::class;
    }

    public function supportsReuse(): bool
    {
        return true;
    }

    public function isManyToMany(): bool
    {
        return false;
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function staticRelated(Relation $relation): Model
    {
        return $relation->getRelated();
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function parentColumns(Relation $relation): array
    {
        /** @var BelongsTo<Model, Model> $relation */
        return [$relation->getForeignKeyName()];
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function fetch(Relation $relation, array $parents, string $name, string $path, array $filters, bool $withoutGlobalScopes, Graph $graph): array
    {
        return $this->eager($relation, $parents, $name, $filters, $withoutGlobalScopes);
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function connect(Graph $graph, GraphNode $parent, GraphNode $target, Relation $relation, ?Model $pivot, string $path): void
    {
        /** @var BelongsTo<Model, Model> $relation */
        $ownerKey = $relation->getOwnerKeyName();

        $parent->assign($target->isCopy()
            ? new LinkAssignment($relation->getForeignKeyName(), $target, $ownerKey, null, $path)
            : new LinkAssignment($relation->getForeignKeyName(), null, '', $this->preserved($parent, $relation->getForeignKeyName(), $target, $ownerKey), $path));
    }
}
