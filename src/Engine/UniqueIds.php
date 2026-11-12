<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use LogicException;

/**
 * Feature detection for the native unique id API of newer Laravel versions.
 *
 * @internal
 */
final class UniqueIds
{
    /**
     * @return list<string>
     */
    public static function columns(object $model): array
    {
        if (! method_exists($model, 'uniqueIds') || ! method_exists($model, 'newUniqueId')) {
            return [];
        }

        $columns = $model->uniqueIds();

        return is_array($columns) ? array_values(array_filter($columns, 'is_string')) : [];
    }

    public static function generate(object $model): mixed
    {
        if (! method_exists($model, 'newUniqueId')) {
            throw new LogicException(get_class($model).' has no newUniqueId() method.');
        }

        return $model->newUniqueId();
    }
}
