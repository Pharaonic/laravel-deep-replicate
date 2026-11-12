<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipStrategyException;

/**
 * @internal
 */
final class Strategy
{
    public const COPY = 'copy';

    public const REUSE = 'reuse';

    public const SKIP = 'skip';

    public static function normalize(string $path, mixed $strategy): string
    {
        if (is_string($strategy)) {
            $normalized = strtolower(trim($strategy));

            if (in_array($normalized, [self::COPY, self::REUSE, self::SKIP], true)) {
                return $normalized;
            }
        }

        throw InvalidRelationshipStrategyException::for($path, $strategy);
    }
}
