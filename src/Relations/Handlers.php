<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Relations;

use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The handler registry of one operation.
 *
 * @internal
 */
final class Handlers
{
    /** @var list<RelationHandler> */
    private array $handlers;

    public function __construct()
    {
        $this->handlers = [
            new HasOneOrManyHandler,
            new BelongsToHandler,
            new MorphToHandler,
            new BelongsToManyHandler,
        ];
    }

    public function for(Relation $relation): ?RelationHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($relation)) {
                return $handler;
            }
        }

        return null;
    }
}
