<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

/**
 * One managed column of a copy, set from another node or to a fixed value.
 *
 * @internal
 */
final class LinkAssignment
{
    public string $column;

    /** The copy node whose inserted attribute provides the value; null for a fixed value. */
    public ?GraphNode $target;

    public string $targetAttribute;

    public mixed $value;

    public string $path;

    /** Inserted as NULL first and updated once the target exists, to break a cycle. */
    public bool $deferred = false;

    public function __construct(string $column, ?GraphNode $target, string $targetAttribute, mixed $value, string $path)
    {
        $this->column = $column;
        $this->target = $target;
        $this->targetAttribute = $targetAttribute;
        $this->value = $value;
        $this->path = $path;
    }

    public function sameAs(self $other): bool
    {
        return $this->target === $other->target
            && ($this->target === null ? $this->value == $other->value : $this->targetAttribute === $other->targetAttribute);
    }
}
