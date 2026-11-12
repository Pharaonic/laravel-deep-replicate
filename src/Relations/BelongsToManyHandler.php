<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use Pharaonic\Laravel\DeepReplicate\Engine\Graph;
use Pharaonic\Laravel\DeepReplicate\Engine\GraphNode;
use Pharaonic\Laravel\DeepReplicate\Engine\PivotLink;
use Pharaonic\Laravel\DeepReplicate\Engine\PivotTarget;

/**
 * BelongsToMany, MorphToMany and MorphedByMany: every source pivot row is
 * recreated for the parent copy, pointing to the copied or reused target.
 *
 * Fresh reads select every column of the pivot table, so extra columns are
 * kept even when the relation does not declare them with withPivot().
 *
 * @internal
 */
final class BelongsToManyHandler implements RelationHandler
{
    use EagerFetching;

    /** @var array<string, list<string>> */
    private array $columns = [];

    /** @var array<string, PivotTarget> */
    private array $targets = [];

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function supports(Relation $relation): bool
    {
        return in_array(get_class($relation), [BelongsToMany::class, MorphToMany::class], true);
    }

    public function supportsReuse(): bool
    {
        return true;
    }

    public function isManyToMany(): bool
    {
        return true;
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
        /** @var BelongsToMany<Model, Model> $pivoted */
        $pivoted = $relation;
        $declared = array_values(array_filter($pivoted->getPivotColumns(), 'is_string'));
        $extra = array_values(array_diff(
            $this->tableColumns($pivoted),
            $declared,
            [$pivoted->getForeignPivotKeyName(), $pivoted->getRelatedPivotKeyName()]
        ));

        if ($extra !== []) {
            $pivoted->withPivot($extra);
        }

        $accessor = $pivoted->getPivotAccessor();

        return $this->eager($relation, $parents, $name, $filters, $withoutGlobalScopes, static function (Model $target) use ($accessor): ?Model {
            $pivot = $target->relationLoaded($accessor) ? $target->getRelation($accessor) : null;

            return $pivot instanceof Model ? $pivot : null;
        });
    }

    /**
     * Columns of the pivot table that a loaded pivot does not carry.
     *
     * @param  BelongsToMany<Model, Model>  $relation
     * @return list<string>
     */
    public function missingColumns(BelongsToMany $relation, Model $pivot): array
    {
        $target = $this->target($relation, '');

        return array_values(array_diff($this->tableColumns($relation), array_keys($pivot->getAttributes()), $target->managedColumns()));
    }

    /**
     * @param  Relation<Model, Model, mixed>  $relation
     */
    public function connect(Graph $graph, GraphNode $parent, GraphNode $target, Relation $relation, ?Model $pivot, string $path): void
    {
        /** @var BelongsToMany<Model, Model> $relation */
        $description = $this->target($relation, $path);
        $attributes = $this->copiedAttributes($pivot === null ? [] : $pivot->getAttributes(), $description->managedColumns());

        $graph->addPivot(new PivotLink($description, $parent, $target, $attributes, $pivot));
    }

    /**
     * The pivot attributes a new pivot row takes over: every column except the managed ones.
     *
     * @param  array<mixed>  $values
     * @param  list<string>  $managed
     * @return array<string, mixed>
     */
    private function copiedAttributes(array $values, array $managed): array
    {
        $attributes = [];

        foreach ($values as $column => $value) {
            if (is_string($column) && ! in_array($column, $managed, true)) {
                $attributes[$column] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @param  BelongsToMany<Model, Model>  $relation
     */
    public function target(BelongsToMany $relation, string $path): PivotTarget
    {
        $key = $path.'|'.get_class($relation->getParent()).'|'.$relation->getTable();

        if (isset($this->targets[$key])) {
            return $this->targets[$key];
        }

        $pivotClass = $relation->getPivotClass();

        if (! is_a($pivotClass, Pivot::class, true)) {
            throw new LogicException("The pivot class [{$pivotClass}] of path [{$path}] does not extend ".Pivot::class.'.');
        }

        $pivot = new $pivotClass;
        $morph = $relation instanceof MorphToMany;

        return $this->targets[$key] = new PivotTarget(
            $path,
            (string) substr((string) strrchr('.'.$path, '.'), 1),
            $relation->getTable(),
            $relation->getForeignPivotKeyName(),
            $relation->getRelatedPivotKeyName(),
            $relation->getParentKeyName(),
            $relation->getRelatedKeyName(),
            $morph ? $relation->getMorphType() : null,
            $morph ? $relation->getMorphClass() : null,
            $pivotClass,
            ! in_array($pivotClass, [Pivot::class, MorphPivot::class], true),
            (string) $pivot->getKeyName(),
            $relation->withTimestamps,
            $relation->createdAt(),
            $relation->updatedAt()
        );
    }

    /**
     * @param  BelongsToMany<Model, Model>  $relation
     * @return list<string>
     */
    private function tableColumns(BelongsToMany $relation): array
    {
        $connection = $relation->getParent()->getConnection();
        $key = $connection->getName().'|'.$relation->getTable();

        if (! isset($this->columns[$key])) {
            $columns = $connection->getSchemaBuilder()->getColumnListing($relation->getTable());
            $this->columns[$key] = array_values(array_filter($columns, 'is_string'));
        }

        return $this->columns[$key];
    }
}
