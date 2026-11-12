<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Reads a relation for many parents the way Eloquent eager loading does.
 *
 * Matching runs on throwaway parent copies, so the caller's models never
 * receive loaded relations.
 *
 * @internal
 */
trait EagerFetching
{
    /**
     * @param  list<Model>  $parents
     * @param  list<callable>  $filters
     * @param  (callable(Model): (Model|null))|null  $pivotOf
     * @return list<list<array{0: Model, 1: Model|null}>>
     */
    protected function eager(Relation $relation, array $parents, string $name, array $filters, bool $withoutGlobalScopes, ?callable $pivotOf = null): array
    {
        if ($withoutGlobalScopes) {
            $relation->getQuery()->withoutGlobalScopes();
        }

        $relation->addEagerConstraints($parents);

        foreach ($filters as $filter) {
            $filter($relation);
        }

        $related = $relation->getRelated();

        if ($related->getKeyName() !== '') {
            $relation->getQuery()->orderBy($related->getQualifiedKeyName());
        }

        $results = $relation->getEager();
        $relation->initRelation($parents, $name);
        $relation->match($parents, $results, $name);

        $targets = [];

        foreach ($parents as $index => $parent) {
            $targets[$index] = [];
            $value = $parent->getRelation($name);

            foreach ($value instanceof Collection ? $value->all() : [$value] as $target) {
                if ($target instanceof Model) {
                    $targets[$index][] = [$target, $pivotOf === null ? null : $pivotOf($target)];
                }
            }
        }

        return array_values($targets);
    }
}
