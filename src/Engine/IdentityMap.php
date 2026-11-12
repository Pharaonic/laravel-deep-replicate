<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsavedSourceModelException;

/**
 * Builds operation-scoped identity keys: resolved connection, model class and
 * a type-preserving primary key, so integer 1 and string "1" stay distinct.
 *
 * @internal
 */
final class IdentityMap
{
    public static function keyFor(Model $model, ?string $path = null): string
    {
        $key = self::tryKeyFor($model);

        if ($key === null || ! $model->exists) {
            throw UnsavedSourceModelException::for(get_class($model), $path);
        }

        return $key;
    }

    public static function tryKeyFor(Model $model): ?string
    {
        $key = $model->getKey();

        if ($key === null || $key === '' || ! is_scalar($key)) {
            return null;
        }

        return $model->getConnection()->getName().'|'.get_class($model).'|'.gettype($key).':'.$key;
    }
}
