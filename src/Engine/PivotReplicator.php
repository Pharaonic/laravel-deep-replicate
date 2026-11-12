<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ProtectedRelationshipAttributeException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationCancelledException;
use UnexpectedValueException;

/**
 * Inserts one new pivot row per source pivot row.
 *
 * Plain pivot tables are written with batched multi-row inserts of at most
 * chunk() rows; custom pivot models (using()) are saved one by one through
 * the relation, like Eloquent's attach(), so their events and casts run.
 *
 * @internal
 */
final class PivotReplicator
{
    private RelationResolver $resolver;

    public function __construct(RelationResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    /**
     * @param  callable(?string, int): \Pharaonic\Laravel\DeepReplicate\ReplicationContext  $context
     */
    public function insert(Graph $graph, callable $context): int
    {
        $definition = $graph->definition;
        $connection = $definition->source->getConnection();
        $buckets = [];
        $count = 0;

        foreach ($graph->pivots as $link) {
            $target = $link->target;
            /** @var Model $parent */
            $parent = $link->parent->copy;
            $related = $link->related->isCopy() ? $link->related->copy : $link->related->source;
            /** @var Model $related */
            $attributes = $link->attributes;

            if ($target->withTimestamps && ! $definition->preservesTimestamps($target->path)) {
                $now = $parent->freshTimestampString();
                $attributes[$target->createdAt] = $now;
                $attributes[$target->updatedAt] = $now;
            }

            foreach ($definition->pivotTransforms[$target->path] ?? [] as $transform) {
                $attributes = $this->attributes(
                    $transform($attributes, $link->sourcePivot, $context($target->path, $link->related->depth)),
                    "A transformPivot() callback for path [{$target->path}] must return an array keyed by column name."
                );

                foreach ($target->managedColumns() as $column) {
                    if (array_key_exists($column, $attributes)) {
                        throw ProtectedRelationshipAttributeException::pivot($target->path, $column);
                    }
                }
            }

            $attributes[$target->foreignPivotKey] = $parent->getAttribute($target->parentKey);
            $attributes[$target->relatedPivotKey] = $related->getAttribute($target->relatedKey);

            if ($target->morphType !== null) {
                $attributes[$target->morphType] = $target->morphClass;
            }

            if ($target->customPivot) {
                $this->saveCustom($graph, $link, $parent, $attributes);
            } else {
                $columns = array_keys($attributes);
                sort($columns);
                $buckets[$target->table][implode(',', $columns)][] = $attributes;
            }

            $count++;
        }

        foreach ($buckets as $table => $groups) {
            foreach ($groups as $rows) {
                foreach (array_chunk($rows, $definition->chunk) as $chunk) {
                    $connection->table($table)->insert($chunk);
                }
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveCustom(Graph $graph, PivotLink $link, Model $parent, array $attributes): void
    {
        $target = $link->target;
        $relation = $this->resolver->resolve($parent, $target->relationName, $target->path);

        if (! $relation instanceof BelongsToMany) {
            throw InvalidRelationshipPathException::notARelation(get_class($parent), $target->path, 'the relation is no longer a many-to-many relation on the copy');
        }

        $pivot = $relation->newPivot($attributes, false);

        if ($graph->definition->preservesTimestamps($target->path)) {
            $pivot->timestamps = false;
        }

        foreach (UniqueIds::columns($pivot) as $column) {
            if ($pivot->getAttribute($column) === null) {
                $pivot->setAttribute($column, UniqueIds::generate($pivot));
            }
        }

        $saved = EventGate::quietly($graph->definition->suppressesEventsFor($target->path), static function () use ($pivot): bool {
            return $pivot->save();
        });

        if ($saved === false) {
            throw ReplicationCancelledException::for(get_class($pivot), $target->path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(mixed $value, string $message): array
    {
        if (! is_array($value)) {
            throw new UnexpectedValueException($message);
        }

        $attributes = [];

        foreach ($value as $column => $item) {
            if (! is_string($column)) {
                throw new UnexpectedValueException($message);
            }

            $attributes[$column] = $item;
        }

        return $attributes;
    }
}
