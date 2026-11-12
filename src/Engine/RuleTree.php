<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\DeepReplicate\Engine;

use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingRelationshipRuleException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;

/**
 * Normalizes the selected paths into one canonical, validated tree.
 *
 * @internal
 */
final class RuleTree
{
    public RuleNode $root;

    /** @var array<string, RuleNode> */
    private array $nodes = [];

    /**
     * @param  list<array{0: string, 1: string}>  $selections  [path, strategy] in call order
     */
    public function __construct(array $selections, string $modelClass)
    {
        $this->root = new RuleNode('', '', Strategy::COPY, true, 0);

        $explicit = [];

        foreach ($selections as [$path, $strategy]) {
            if (isset($explicit[$path]) && $explicit[$path] !== $strategy) {
                throw ConflictingRelationshipRuleException::for($path, $explicit[$path], $strategy);
            }

            $explicit[$path] = $strategy;
        }

        foreach ($explicit as $path => $strategy) {
            $this->insert($path, $strategy);
        }

        foreach ($this->nodes as $node) {
            $parent = $this->parentOf($node);

            if ($parent !== null && $node->isActive() && $parent->strategy !== Strategy::COPY) {
                throw IncompatibleRelationOptionsException::nestedUnder($parent->path, $parent->strategy, $node->path, $node->strategy);
            }
        }

        if (! $this->root->hasActiveChildren()) {
            throw EmptyRelationshipSelectionException::for($modelClass);
        }
    }

    public static function assertPath(mixed $path): string
    {
        if (! is_string($path) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/', $path) !== 1) {
            throw InvalidRelationshipPathException::syntax($path);
        }

        return $path;
    }

    public function get(string $path): ?RuleNode
    {
        return $this->nodes[$path] ?? null;
    }

    /**
     * Every path with its strategy, implied parents included, in tree order.
     *
     * @return array<string, string>
     */
    public function paths(): array
    {
        $paths = [];

        $walk = function (RuleNode $node) use (&$walk, &$paths): void {
            foreach ($node->children as $child) {
                if ($child->isActive() || $child->explicit) {
                    $paths[$child->path] = $child->strategy;
                }

                $walk($child);
            }
        };

        $walk($this->root);

        return $paths;
    }

    /**
     * @return list<RuleNode>
     */
    public function nodes(): array
    {
        return array_values($this->nodes);
    }

    public function countSkipped(): int
    {
        return count(array_filter($this->nodes, static function (RuleNode $node): bool {
            return ! $node->isActive() && $node->explicit;
        }));
    }

    public function maxActiveDepth(): ?RuleNode
    {
        $deepest = null;

        foreach ($this->nodes as $node) {
            if ($node->isActive() && ($deepest === null || $node->depth > $deepest->depth)) {
                $deepest = $node;
            }
        }

        return $deepest;
    }

    private function insert(string $path, string $strategy): void
    {
        $segments = explode('.', $path);
        $parent = $this->root;
        $current = '';

        foreach ($segments as $depth => $segment) {
            $current = $current === '' ? $segment : $current.'.'.$segment;
            $last = $depth === count($segments) - 1;

            // A parent is implied as copy only by an active descendant; a skipped
            // descendant implies nothing, so it never adds a traversal path.
            $implied = $strategy === Strategy::SKIP ? Strategy::SKIP : Strategy::COPY;

            if (! isset($parent->children[$segment])) {
                $node = new RuleNode($segment, $current, $last ? $strategy : $implied, $last, $depth + 1);
                $parent->children[$segment] = $node;
                $this->nodes[$current] = $node;
            } elseif ($last) {
                // An implied parent becomes explicit: explicit wins over implied.
                $node = $parent->children[$segment];
                $node->strategy = $strategy;
                $node->explicit = true;
            } elseif (! $parent->children[$segment]->explicit && $implied === Strategy::COPY) {
                $parent->children[$segment]->strategy = Strategy::COPY;
            }

            $parent = $parent->children[$segment];
        }
    }

    private function parentOf(RuleNode $node): ?RuleNode
    {
        $position = strrpos($node->path, '.');

        return $position === false ? null : $this->nodes[substr($node->path, 0, $position)];
    }
}
