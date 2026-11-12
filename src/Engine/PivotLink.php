<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Illuminate\Database\Eloquent\Model;

/**
 * One source pivot row to recreate for the replica.
 *
 * @internal
 */
final class PivotLink
{
    public PivotTarget $target;

    public GraphNode $parent;

    public GraphNode $related;

    /** @var array<string, mixed> raw non-linking attributes of the source row */
    public array $attributes;

    public ?Model $sourcePivot;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(PivotTarget $target, GraphNode $parent, GraphNode $related, array $attributes, ?Model $sourcePivot)
    {
        $this->target = $target;
        $this->parent = $parent;
        $this->related = $related;
        $this->attributes = $attributes;
        $this->sourcePivot = $sourcePivot;
    }
}
