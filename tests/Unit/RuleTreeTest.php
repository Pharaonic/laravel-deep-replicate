<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Unit;

use Pharaonic\Laravel\DeepReplicate\Engine\RuleTree;
use Pharaonic\Laravel\DeepReplicate\Engine\Strategy;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingRelationshipRuleException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipStrategyException;
use PHPUnit\Framework\TestCase;

class RuleTreeTest extends TestCase
{
    public function test_it_builds_a_canonical_tree_with_implied_parents(): void
    {
        $tree = new RuleTree([['items.options', 'copy'], ['items.product', 'reuse'], ['payments', 'skip'], ['customer', 'reuse']], 'Model');

        $this->assertSame(
            ['items' => 'copy', 'items.options' => 'copy', 'items.product' => 'reuse', 'payments' => 'skip', 'customer' => 'reuse'],
            $tree->paths()
        );
        $this->assertFalse($tree->get('items')->explicit);
        $this->assertSame(2, $tree->get('items.options')->depth);
        $this->assertSame(1, $tree->countSkipped());
        $this->assertSame('items.options', $tree->maxActiveDepth()->path);
    }

    public function test_an_explicit_strategy_replaces_an_implied_parent(): void
    {
        $tree = new RuleTree([['items.options', 'copy'], ['items', 'copy']], 'Model');

        $this->assertTrue($tree->get('items')->explicit);
    }

    public function test_skip_descendants_imply_nothing_until_an_active_path_needs_the_parent(): void
    {
        $tree = new RuleTree([['items.options', 'skip'], ['items.tags', 'copy']], 'Model');

        $this->assertSame(['items' => 'copy', 'items.options' => 'skip', 'items.tags' => 'copy'], $tree->paths());

        $this->expectException(EmptyRelationshipSelectionException::class);
        new RuleTree([['items.options', 'skip']], 'Model');
    }

    public function test_conflicts_are_detected_in_either_order(): void
    {
        foreach ([[['a', 'copy'], ['a', 'reuse']], [['a', 'reuse'], ['a', 'copy']]] as $selections) {
            try {
                new RuleTree($selections, 'Model');
                $this->fail('A conflict must throw.');
            } catch (ConflictingRelationshipRuleException $exception) {
                $this->assertStringContainsString('[a]', $exception->getMessage());
            }
        }
    }

    public function test_active_paths_under_skip_or_reuse_are_incompatible_but_skips_are_allowed(): void
    {
        foreach ([[['a', 'skip'], ['a.b', 'copy']], [['a', 'reuse'], ['a.b', 'reuse']], [['a.b', 'copy'], ['a', 'reuse']]] as $selections) {
            try {
                new RuleTree($selections, 'Model');
                $this->fail('An active path under a terminal must throw.');
            } catch (IncompatibleRelationOptionsException $exception) {
                $this->assertStringContainsString('[a.b]', $exception->getMessage());
            }
        }

        $tree = new RuleTree([['a', 'reuse'], ['a.b', 'skip'], ['c', 'skip'], ['c.d', 'skip']], 'Model');
        $this->assertSame(['a' => 'reuse', 'a.b' => 'skip', 'c' => 'skip', 'c.d' => 'skip'], $tree->paths());
    }

    public function test_paths_and_strategies_are_validated(): void
    {
        $this->assertSame('a.b_c.D1', RuleTree::assertPath('a.b_c.D1'));
        $this->assertSame(Strategy::REUSE, Strategy::normalize('a', ' ReUse '));

        foreach (['', 'a.', '.a', 'a..b', 'a b', 'a-b', '9a', 1, null, ['a']] as $path) {
            try {
                RuleTree::assertPath($path);
                $this->fail('An invalid path must throw.');
            } catch (InvalidRelationshipPathException $exception) {
                $this->assertStringContainsString('invalid', $exception->getMessage());
            }
        }

        $this->expectException(InvalidRelationshipStrategyException::class);
        Strategy::normalize('a', 'duplicate');
    }
}
