<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;

/**
 * Suppresses Eloquent model events through Laravel's own Model::withoutEvents(),
 * which swaps in a null dispatcher for the callback only and always restores
 * the original one. Listener registration keeps working meanwhile.
 *
 * @internal
 */
final class EventGate
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function quietly(bool $quiet, callable $callback)
    {
        if (! $quiet) {
            return $callback();
        }

        $result = null;

        Model::withoutEvents(static function () use ($callback, &$result): void {
            $result = $callback();
        });

        /** @var T $result */
        return $result;
    }
}
