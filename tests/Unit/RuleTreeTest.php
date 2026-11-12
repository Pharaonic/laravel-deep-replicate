<?php

use Pharaonic\Laravel\DeepReplicate\Engine\RuleTree;
use Pharaonic\Laravel\DeepReplicate\Engine\Strategy;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingRelationshipRuleException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipStrategyException;

test('it builds a canonical tree with implied parents', function () {
    $tree = new RuleTree([['items.options', 'copy'], ['items.product', 'reuse'], ['payments', 'skip'], ['customer', 'reuse']], 'Model');

    expect($tree->paths())->toBe(['items' => 'copy', 'items.options' => 'copy', 'items.product' => 'reuse', 'payments' => 'skip', 'customer' => 'reuse']);
    expect($tree->get('items')->explicit)->toBeFalse();
    expect($tree->get('items.options')->depth)->toBe(2);
    expect($tree->countSkipped())->toBe(1);
    expect($tree->maxActiveDepth()->path)->toBe('items.options');
});

test('an explicit strategy replaces an implied parent', function () {
    $tree = new RuleTree([['items.options', 'copy'], ['items', 'copy']], 'Model');

    expect($tree->get('items')->explicit)->toBeTrue();
});

test('skip descendants imply nothing until an active path needs the parent', function () {
    $tree = new RuleTree([['items.options', 'skip'], ['items.tags', 'copy']], 'Model');

    expect($tree->paths())->toBe(['items' => 'copy', 'items.options' => 'skip', 'items.tags' => 'copy']);

    $this->expectException(EmptyRelationshipSelectionException::class);
    new RuleTree([['items.options', 'skip']], 'Model');
});

test('conflicts are detected in either order', function () {
    foreach ([[['a', 'copy'], ['a', 'reuse']], [['a', 'reuse'], ['a', 'copy']]] as $selections) {
        try {
            new RuleTree($selections, 'Model');
            $this->fail('A conflict must throw.');
        } catch (ConflictingRelationshipRuleException $exception) {
            expect($exception->getMessage())->toContain('[a]');
        }
    }
});

test('active paths under skip or reuse are incompatible but skips are allowed', function () {
    foreach ([[['a', 'skip'], ['a.b', 'copy']], [['a', 'reuse'], ['a.b', 'reuse']], [['a.b', 'copy'], ['a', 'reuse']]] as $selections) {
        try {
            new RuleTree($selections, 'Model');
            $this->fail('An active path under a terminal must throw.');
        } catch (IncompatibleRelationOptionsException $exception) {
            expect($exception->getMessage())->toContain('[a.b]');
        }
    }

    $tree = new RuleTree([['a', 'reuse'], ['a.b', 'skip'], ['c', 'skip'], ['c.d', 'skip']], 'Model');
    expect($tree->paths())->toBe(['a' => 'reuse', 'a.b' => 'skip', 'c' => 'skip', 'c.d' => 'skip']);
});

test('paths and strategies are validated', function () {
    expect(RuleTree::assertPath('a.b_c.D1'))->toBe('a.b_c.D1');
    expect(Strategy::normalize('a', ' ReUse '))->toBe(Strategy::REUSE);

    foreach (['', 'a.', '.a', 'a..b', 'a b', 'a-b', '9a', 1, null, ['a']] as $path) {
        try {
            RuleTree::assertPath($path);
            $this->fail('An invalid path must throw.');
        } catch (InvalidRelationshipPathException $exception) {
            expect($exception->getMessage())->toContain('invalid');
        }
    }

    $this->expectException(InvalidRelationshipStrategyException::class);
    Strategy::normalize('a', 'duplicate');
});
