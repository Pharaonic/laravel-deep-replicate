<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

/**
 * One relation path of the normalized selection tree.
 *
 * @internal
 */
final class RuleNode
{
    public string $name;

    /** The full dot path; '' for the root. */
    public string $path;

    public string $strategy;

    public bool $explicit;

    public int $depth;

    /** @var array<string, RuleNode> */
    public array $children = [];

    public function __construct(string $name, string $path, string $strategy, bool $explicit, int $depth)
    {
        $this->name = $name;
        $this->path = $path;
        $this->strategy = $strategy;
        $this->explicit = $explicit;
        $this->depth = $depth;
    }

    public function isActive(): bool
    {
        return $this->strategy !== Strategy::SKIP;
    }

    public function hasActiveChildren(): bool
    {
        foreach ($this->children as $child) {
            if ($child->isActive()) {
                return true;
            }
        }

        return false;
    }
}
