<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Pharaonic\Laravel\DeepReplicate\Engine\Graph;
use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;
use Pharaonic\Laravel\DeepReplicate\Engine\LinkAssignment;

/**
 * HasOne, HasMany, MorphOne and MorphMany: the child holds the foreign key
 * (and morph type), so each child copy is linked to the parent copy.
 *
 * @internal
 */
final class HasOneOrManyHandler implements RelationHandler
{
    use EagerFetching;

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function supports(Relation $relation): bool
    {
        return in_array(get_class($relation), [HasOne::class, HasMany::class, MorphOne::class, MorphMany::class], true);
    }

    public function supportsReuse(): bool
    {
        return false;
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
        return [];
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
        /** @var HasOneOrMany<Model, Model, mixed> $relation */
        $target->assign(new LinkAssignment($relation->getForeignKeyName(), $parent, $relation->getLocalKeyName(), null, $path));

        if ($relation instanceof MorphOneOrMany) {
            $target->assign(new LinkAssignment($relation->getMorphType(), null, '', $relation->getMorphClass(), $path));
        }
    }
}
