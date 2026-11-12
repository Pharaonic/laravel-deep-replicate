<?php

use Pharaonic\Laravel\DeepReplicate\RelationRule;

test('a new rule is empty', function () {
    $rule = new RelationRule;

    expect($rule->isEmpty())->toBeTrue();
    expect((new RelationRule)->withoutEvents()->isEmpty())->toBeFalse();
    expect((new RelationRule)->preserveTimestamps(false)->isEmpty())->toBeFalse();
});

test('settings accumulate', function () {
    $first = function (array $attributes) {
        return $attributes;
    };
    $rule = (new RelationRule)
        ->exclude(['a'])
        ->exclude(['b', 'a'])
        ->with(['x' => 1])
        ->with(['y' => 2, 'x' => 3])
        ->transform($first)
        ->transform($first);

    expect($rule->excludedAttributes())->toBe(['a', 'b']);
    expect($rule->attributeOverrides())->toBe(['x' => 3, 'y' => 2]);
    expect($rule->transforms())->toHaveCount(2);
});

test('a generator is registered once per attribute', function () {
    $rule = (new RelationRule)->generate('slug', 'strtoupper');

    $this->expectException(InvalidArgumentException::class);
    $rule->generate('slug', 'strtolower');
});

test('same as compares values and callbacks by identity', function () {
    $callback = function () {};

    expect((new RelationRule)->with(['a' => 1])->generate('b', $callback)->sameAs((new RelationRule)->with(['a' => 1])->generate('b', $callback)))->toBeTrue();
    expect((new RelationRule)->generate('b', $callback)->sameAs((new RelationRule)->generate('b', function () {})))->toBeFalse();
    expect((new RelationRule)->with(['a' => 1])->sameAs((new RelationRule)->with(['a' => 2])))->toBeFalse();
    expect((new RelationRule)->exclude(['a'])->sameAs(new RelationRule))->toBeFalse();
});

test('over gives the rule precedence per setting', function () {
    $defaultTransform = function () {};
    $builderTransform = function () {};
    $defaults = (new RelationRule)->exclude(['d'])->with(['a' => 'default', 'b' => 'default'])->transform($defaultTransform)->withoutEvents();
    $builder = (new RelationRule)->with(['a' => 'builder'])->transform($builderTransform)->preserveTimestamps();

    $effective = $builder->over($defaults);

    expect($effective->excludedAttributes())->toBe(['d']);
    expect($effective->attributeOverrides())->toBe(['a' => 'builder', 'b' => 'default']);
    expect($effective->transforms())->toBe([$builderTransform]);
    expect($effective->eventsSuppressed())->toBeTrue();
    expect($effective->timestampsPreserved())->toBeTrue();
    expect((new RelationRule)->over($defaults)->transforms())->toBe([$defaultTransform]);
});

test('defaults are validated', function () {
    $rule = RelationRule::fromDefaults(['exclude' => ['a'], 'with' => ['b' => 1], 'generate' => ['c' => 'strtoupper'], 'without_events' => true, 'preserve_timestamps' => false], 'root');

    expect($rule->excludedAttributes())->toBe(['a']);
    expect($rule->eventsSuppressed())->toBeTrue();
    expect($rule->timestampsPreserved())->toBeFalse();

    foreach ([['unknown' => 1], ['exclude' => 'a'], ['exclude' => [1]], ['with' => ['x']], ['generate' => ['a' => 'not callable']], ['transform' => 1], ['without_events' => 'yes'], ['preserve_timestamps' => 1]] as $invalid) {
        try {
            RelationRule::fromDefaults($invalid, 'root');
            $this->fail('Invalid defaults must throw: '.json_encode($invalid));
        } catch (InvalidArgumentException $exception) {
            expect($exception->getMessage())->toContain('deepReplicationDefaults()');
        }
    }
});
