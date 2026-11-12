<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

/**
 * Compares stored attribute values the same way on every database driver:
 * PDO returns integers as strings on some drivers and PHP versions, so
 * scalars are compared by their string value.
 *
 * @internal
 */
final class AttributeValues
{
    /**
     * Whether a protected column differs between two attribute sets.
     *
     * @param  array<mixed>  $before  attributes as Eloquent returns them
     * @param  array<mixed>  $after
     */
    public static function changed(string $column, array $before, array $after): bool
    {
        $had = array_key_exists($column, $before);

        if ($had !== array_key_exists($column, $after)) {
            return true;
        }

        if (! $had) {
            return false;
        }

        $old = $before[$column];
        $new = $after[$column];

        if (is_scalar($old) && is_scalar($new)) {
            return (string) $old !== (string) $new;
        }

        return $old !== $new;
    }
}
