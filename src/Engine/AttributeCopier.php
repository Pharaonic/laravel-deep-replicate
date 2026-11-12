<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Exceptions\MissingPrimaryKeyGeneratorException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ProtectedRelationshipAttributeException;
use Pharaonic\Laravel\DeepReplicate\ReplicationContext;
use UnexpectedValueException;

/**
 * Prepares the unsaved copy of one node, in a fixed order:
 * native replicate() and managed-field stripping, exclude, with, generate,
 * transform, primary key, then the fixed linking values.
 *
 * @internal
 */
final class AttributeCopier
{
    public function prepare(GraphNode $node, Definition $definition, ReplicationContext $context): void
    {
        $source = $node->source;
        $rule = $definition->ruleFor($node->rulePath);
        $path = $node->path === '' ? null : $node->path;

        /** @var Model $copy */
        $copy = EventGate::quietly($definition->suppressesEventsFor($node->rulePath), static function () use ($source): Model {
            return $source->replicate();
        });

        // Native replicate() may carry the source's loaded relations over.
        $copy->setRelations([]);
        $copy->setConnection($source->getConnectionName());

        $raw = $source->getAttributes();
        $attributes = $copy->getAttributes();
        $keyName = $copy->getKeyName();
        unset($attributes[$keyName]);

        // Laravel 9.30+ replicate() drops unique id columns too; do it on every line.
        foreach (UniqueIds::columns($copy) as $column) {
            unset($attributes[$column]);
        }

        if ($definition->preservesTimestamps($node->rulePath)) {
            foreach ([$copy->getCreatedAtColumn(), $copy->getUpdatedAtColumn()] as $column) {
                if (is_string($column) && array_key_exists($column, $raw)) {
                    $attributes[$column] = $raw[$column];
                }
            }
        }

        if (! $definition->preserveSoftDelete && method_exists($copy, 'getDeletedAtColumn')) {
            $column = $copy->getDeletedAtColumn();

            if (is_string($column) && array_key_exists($column, $attributes)) {
                $attributes[$column] = null;
            }
        }

        foreach ($rule->excludedAttributes() ?? [] as $attribute) {
            unset($attributes[$attribute]);
        }

        $copy->setRawAttributes($attributes);

        foreach ($rule->attributeOverrides() as $attribute => $value) {
            $copy->setAttribute($attribute, $value);
        }

        foreach ($rule->generators() as $attribute => $generator) {
            $copy->setAttribute($attribute, $generator($source, $context));
        }

        $protected = array_keys($node->managed);
        $protected[] = $keyName;

        foreach ($rule->transforms() as $transform) {
            $before = $copy->getAttributes();
            $after = $transform($before, $source, $context);

            if (! is_array($after) || array_filter(array_keys($after), 'is_string') !== array_keys($after)) {
                throw new UnexpectedValueException('A transform() callback for '.($path === null ? 'the root model' : "path [{$path}]").' must return an array keyed by attribute name.');
            }

            foreach ($protected as $column) {
                if (AttributeValues::changed($column, $before, $after)) {
                    throw ProtectedRelationshipAttributeException::for(get_class($source), $path, $column, 'transform()');
                }
            }

            $copy->setRawAttributes($after);
        }

        $this->assignUniqueIds($copy);

        $key = $copy->getAttribute($keyName);

        if (! $copy->getIncrementing() && ($key === null || $key === '')) {
            throw MissingPrimaryKeyGeneratorException::for(get_class($source), $path, $keyName);
        }

        $attributes = $copy->getAttributes();

        foreach ($node->links as $link) {
            if ($link->target === null) {
                $attributes[$link->column] = $link->value;
            }
        }

        $copy->setRawAttributes($attributes);

        $node->copy = $copy;
        $node->state = GraphNode::PREPARED;
    }

    /**
     * Fill empty unique id columns through the model's native generator:
     * uniqueIds() and newUniqueId(), as HasUuids and HasUlids define them
     * from Laravel 9.30.
     */
    private function assignUniqueIds(Model $copy): void
    {
        $columns = UniqueIds::columns($copy);

        foreach ($columns as $column) {
            $value = $copy->getAttribute($column);

            if ($value === null || $value === '') {
                $copy->setAttribute($column, UniqueIds::generate($copy));
            }
        }
    }
}
