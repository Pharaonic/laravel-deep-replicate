<?php

use Pharaonic\Laravel\DeepReplicate\Concerns\DeepReplicates;
use Pharaonic\Laravel\DeepReplicate\DeepReplicator;
use Pharaonic\Laravel\DeepReplicate\Exceptions\BuilderAlreadyExecutedException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingRelationshipRuleException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\DeepReplicationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipStrategyException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsavedSourceModelException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\ReplicationBuilder;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;

test('the trait requires a first argument', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    try {
        // @phpstan-ignore-next-line
        $order->deepReplicate();
        $this->fail('deepReplicate() without arguments must fail.');
    } catch (ArgumentCountError $error) {
        expect($error->getMessage())->toContain('deepReplicate');
    }

    expect($this->counts())->toBe($before);
});

test('empty selections are rejected before any write', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $attempts = [
        'trait empty array' => function () use ($order) {
            $order->deepReplicate([]);
        },
        'facade without selection' => function () use ($order) {
            DeepReplicate::for($order)->replicate();
        },
        'facade empty array' => function () use ($order) {
            DeepReplicate::for($order, []);
        },
        'trait skip only' => function () use ($order) {
            $order->deepReplicate(['payments' => 'skip'])->replicate();
        },
        'facade skip only' => function () use ($order) {
            DeepReplicate::for($order)->skip('payments')->replicate();
        },
        'nested skip only' => function () use ($order) {
            DeepReplicate::for($order)->skip('items.options')->replicate();
        },
        'dry run without selection' => function () use ($order) {
            DeepReplicate::for($order)->dryRun();
        },
    ];

    foreach ($attempts as $name => $attempt) {
        $writes = $this->captureWrites(function () use ($attempt, $name) {
            try {
                $attempt();
                $this->fail("{$name} must throw.");
            } catch (EmptyRelationshipSelectionException $exception) {
                expect($exception->getMessage())->toContain(Order::class);
            }
        });

        $this->assertSame([], $writes, $name);
    }

    expect($this->counts())->toBe($before);
});

test('a skipped nested path does not imply its parent', function () {
    $order = $this->seedOrder();

    $plan = DeepReplicate::for($order)->copy('customer')->skip('items.options')->dryRun();

    expect($plan->relationships())->toBe(['customer' => 'copy', 'items.options' => 'skip']);
    expect($plan->stats()->copiedModels())->toBe(2);
});

test('selection syntaxes produce the same canonical plan', function () {
    $order = $this->seedOrder();
    $expected = ['items' => 'copy', 'items.options' => 'copy', 'items.product' => 'reuse', 'customer' => 'reuse', 'payments' => 'skip'];

    $plans = [
        DeepReplicate::for($order)->copy('items', 'items.options')->reuse('items.product', 'customer')->skip('payments'),
        DeepReplicate::for($order, ['items', 'items.options', 'items.product' => 'reuse', 'customer' => 'REUSE', 'payments' => ' Skip ']),
        $order->deepReplicate('items', 'items.options')->reuse('items.product', 'customer')->skip('payments'),
        $order->deepReplicate(['items' => 'copy', 'items.options' => 'Copy', 'items.product' => 'reuse', 'customer' => 'reuse', 'payments' => 'skip']),
        $order->deepReplicate(['items.options'])->relations(['items.product' => 'reuse'])->reuse('customer')->skip('payments'),
    ];

    foreach ($plans as $index => $builder) {
        $relationships = $builder->dryRun()->relationships();
        ksort($relationships);
        $sorted = $expected;
        ksort($sorted);

        $this->assertSame($sorted, $relationships, "syntax #{$index}");
    }
});

test('the facade and the trait build equivalent builders', function () {
    $order = $this->seedOrder();

    $viaFacade = DeepReplicate::for($order, 'items')->reuse('customer');
    $viaTrait = $order->deepReplicate('items')->reuse('customer');
    $viaService = $this->app->make(DeepReplicator::class)->for($order, ['items'])->reuse('customer');

    expect($viaFacade)->toBeInstanceOf(ReplicationBuilder::class);
    expect($viaTrait)->toBeInstanceOf(ReplicationBuilder::class);
    expect($viaTrait->dryRun()->stats()->copiedModels())->toEqual($viaFacade->dryRun()->stats()->toArray()['copiedModels']);
    expect($viaTrait->dryRun()->relationships())->toBe($viaFacade->dryRun()->relationships());
    expect($viaService->dryRun()->relationships())->toBe($viaTrait->dryRun()->relationships());
    expect($viaTrait->replicate()->stats()->copiedModels())->toBe(3);
    expect($viaFacade->replicate()->stats()->copiedModels())->toBe(3);
});

test('the trait keeps the native replicate method', function () {
    $order = $this->seedOrder();

    expect(method_exists(DeepReplicates::class, 'replicate'))->toBeFalse();

    $clone = $order->replicate();

    expect($clone)->toBeInstanceOf(Order::class);
    expect($clone->exists)->toBeFalse();
    expect(Order::count())->toBe(1);
});

test('repeating a strategy is idempotent', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)->copy('items')->copy('items')->relations(['items' => 'copy', 'items'])->replicate();

    expect($result->stats()->copiedModels())->toBe(3);
});

test('conflicting strategies throw regardless of order', function () {
    $order = $this->seedOrder();

    foreach ([['copy', 'reuse'], ['reuse', 'copy'], ['copy', 'skip'], ['skip', 'reuse']] as [$first, $second]) {
        try {
            DeepReplicate::for($order)->{$first}('items')->{$second}('items')->replicate();
            $this->fail("{$first} then {$second} must conflict.");
        } catch (ConflictingRelationshipRuleException $exception) {
            expect($exception->getMessage())->toContain('[items]');
        }
    }

    expect(Order::count())->toBe(1);
});

test('a nested path implies copying its parents', function () {
    $order = $this->seedOrder();

    $plan = DeepReplicate::for($order)->copy('items.options')->dryRun();

    expect($plan->relationships())->toBe(['items' => 'copy', 'items.options' => 'copy']);
    expect($plan->estimatedModels())->toBe(6);
});

test('an active path under a skipped or reused parent is rejected', function () {
    $order = $this->seedOrder();

    $cases = [
        function () use ($order) {
            DeepReplicate::for($order)->skip('items')->copy('items.options')->replicate();
        },
        function () use ($order) {
            DeepReplicate::for($order)->copy('items.options')->skip('items')->replicate();
        },
        function () use ($order) {
            DeepReplicate::for($order)->reuse('customer')->copy('customer.orders')->replicate();
        },
        function () use ($order) {
            DeepReplicate::for($order)->reuse('customer.orders')->reuse('customer')->replicate();
        },
    ];

    foreach ($cases as $index => $case) {
        try {
            $case();
            $this->fail("case #{$index} must throw.");
        } catch (IncompatibleRelationOptionsException $exception) {
            expect($exception->getMessage())->toMatch('/under \[(items|customer)\]/');
        }
    }

    expect(Order::count())->toBe(1);
});

test('invalid paths and strategies are rejected', function () {
    $order = $this->seedOrder();

    foreach (['', 'items..options', ' items', 'items.', '1items', 'items-x'] as $path) {
        try {
            DeepReplicate::for($order)->copy($path);
            $this->fail("path [{$path}] must be rejected.");
        } catch (InvalidRelationshipPathException $exception) {
            expect($exception->getMessage())->toContain('invalid');
        }
    }

    $this->assertThrown(InvalidRelationshipPathException::class, function () use ($order) {
        DeepReplicate::for($order, [123]);
    });

    foreach (['clone', '', null, 1, true, ['copy'], function () {
    }] as $strategy) {
        $this->assertThrown(InvalidRelationshipStrategyException::class, function () use ($order, $strategy) {
            DeepReplicate::for($order, ['items' => $strategy]);
        });
    }
});

test('non relation methods are rejected safely with model and path', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $cases = [
        'missing' => 'does not exist',
        'hidden' => 'not a public',
        'staticRelation' => 'not a public',
        'withArgument' => 'not a public',
        'label' => 'return type',
        'computed' => 'returned int',
        'delete' => 'framework or package method',
        'save' => 'framework or package method',
        'replicate' => 'framework or package method',
        'forceDelete' => 'framework or package method',
        'restore' => 'framework or package method',
        'deepReplicate' => 'not a public',
        'deepReplicationDefaults' => 'not a public',
    ];

    foreach ($cases as $path => $reason) {
        try {
            DeepReplicate::for($order)->copy($path)->replicate();
            $this->fail("{$path} must be rejected.");
        } catch (InvalidRelationshipPathException $exception) {
            expect($exception->getMessage())->toContain("[{$path}]");
            expect($exception->getMessage())->toContain(Order::class);
            $this->assertStringContainsString($reason, $exception->getMessage(), $path);
        }
    }

    expect($this->counts())->toBe($before);
    expect($order->fresh()->deleted_at)->toBeNull();
});

test('nested invalid paths name the nested model', function () {
    $order = $this->seedOrder();

    try {
        DeepReplicate::for($order)->copy('items.nothing')->dryRun();
        $this->fail('items.nothing must be rejected.');
    } catch (InvalidRelationshipPathException $exception) {
        expect($exception->getMessage())->toContain('[items.nothing]');
        expect($exception->getMessage())->toContain('OrderItem');
    }
});

test('options must target selected paths with a compatible strategy', function () {
    $order = $this->seedOrder();
    $noop = function () {
    };

    $this->assertThrown(InvalidRelationshipPathException::class, function () use ($order, $noop) {
        DeepReplicate::for($order)->copy('items')->filter('payments', $noop)->dryRun();
    });

    $this->assertThrown(InvalidRelationshipPathException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->usingLoaded('payments')->dryRun();
    });

    $this->assertThrown(IncompatibleRelationOptionsException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->reuse('customer')->configure('customer', function ($rule) {
            $rule->exclude(['name']);
        })->dryRun();
    });

    $this->assertThrown(IncompatibleRelationOptionsException::class, function () use ($order, $noop) {
        DeepReplicate::for($order)->copy('items')->skip('payments')->filter('payments', $noop)->dryRun();
    });

    $this->assertThrown(IncompatibleRelationOptionsException::class, function () use ($order, $noop) {
        DeepReplicate::for($order)->copy('items')->filter('items', $noop)->usingLoaded('items')->dryRun();
    });

    $this->assertThrown(IncompatibleRelationOptionsException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->copyPivot('items')->dryRun();
    });

    $this->assertThrown(IncompatibleRelationOptionsException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->transformPivot('items', function (array $attributes) {
            return $attributes;
        })->dryRun();
    });
});

test('limits must be positive', function () {
    $builder = DeepReplicate::for($this->seedOrder());

    foreach (['maxDepth', 'maxModels', 'maxPivotRows', 'chunk'] as $method) {
        foreach ([0, -1] as $value) {
            $this->assertThrown(InvalidArgumentException::class, function () use ($builder, $method, $value) {
                $builder->{$method}($value);
            });
        }

        expect($builder->{$method}(1))->toBe($builder);
    }
});

test('builders are single use', function () {
    $order = $this->seedOrder();
    $builder = DeepReplicate::for($order)->copy('items');

    $builder->dryRun();
    $builder->dryRun();
    $builder->replicate();

    $this->assertThrown(BuilderAlreadyExecutedException::class, function () use ($builder) {
        $builder->replicate();
    });
    $this->assertThrown(BuilderAlreadyExecutedException::class, function () use ($builder) {
        $builder->dryRun();
    });

    $failing = DeepReplicate::for($order)->copy('items')->beforeReplicate(function () {
        throw new \RuntimeException('stop');
    });

    $this->assertThrown(\RuntimeException::class, function () use ($failing) {
        $failing->replicate();
    });
    $this->assertThrown(BuilderAlreadyExecutedException::class, function () use ($failing) {
        $failing->replicate();
    });

    expect(Order::count())->toBe(2);
});

test('builders do not share state', function () {
    $order = $this->seedOrder();

    $first = DeepReplicate::for($order)->copy('items')->with(['status' => 'one']);
    $second = DeepReplicate::for($order)->copy('payments')->with(['status' => 'two']);

    expect($first->dryRun()->relationships())->toBe(['items' => 'copy']);
    expect($second->dryRun()->relationships())->toBe(['payments' => 'copy']);
    expect($second->replicate()->model()->status)->toBe('two');
    expect($first->replicate()->model()->status)->toBe('one');
});

test('an unsaved source is rejected', function () {
    $this->assertThrown(UnsavedSourceModelException::class, function () {
        DeepReplicate::for(new Order(['number' => 'x']))->copy('items')->replicate();
    });

    expect(Order::count())->toBe(0);
});

test('every package exception extends the base exception', function () {
    foreach (glob(__DIR__.'/../../src/Exceptions/*.php') ?: [] as $file) {
        $class = 'Pharaonic\\Laravel\\DeepReplicate\\Exceptions\\'.basename($file, '.php');

        $this->assertTrue(is_a($class, DeepReplicationException::class, true), $class);
        $this->assertTrue(is_a($class, \RuntimeException::class, true), $class);
    }
});

test('model defaults configure selected paths only', function () {
    $order = $this->seedOrder();
    Order::$defaults = [
        'with' => ['status' => 'from-defaults', 'external_ref' => null],
        'paths' => [
            'items' => ['exclude' => ['external_id'], 'with' => ['state' => 'pending']],
            'payments' => ['with' => ['amount' => 0]],
        ],
    ];

    $result = $order->deepReplicate('items')->with(['status' => 'from-builder'])->replicate();
    $copy = $result->model();

    expect($copy->status)->toBe('from-builder');
    expect($copy->external_ref)->toBeNull();
    expect($copy->items()->pluck('state')->all())->toBe(['pending', 'pending']);
    expect($copy->items()->pluck('external_id')->all())->toBe([null, null]);
    expect($copy->payments()->count())->toBe(0);
    expect($order->payments()->count())->toBe(2);
});

test('model defaults cannot select paths or change strategies', function () {
    $order = $this->seedOrder();

    Order::$defaults = ['paths' => ['customer' => ['with' => ['name' => 'changed']]]];
    $result = $order->deepReplicate('items')->reuse('customer')->replicate();
    expect($result->stats()->copiedModels() - 3)->toBe(0);
    expect($result->model()->customer->name)->toBe('Alice');

    Order::$defaults = ['paths' => ['items' => ['strategy' => 'reuse']]];
    $this->assertThrown(InvalidArgumentException::class, function () use ($order) {
        $order->deepReplicate('items')->dryRun();
    });

    Order::$defaults = ['relations' => ['items']];
    $this->assertThrown(InvalidArgumentException::class, function () use ($order) {
        $order->deepReplicate('customer')->dryRun();
    });
});

test('the builder configuration wins over model defaults per setting', function () {
    $order = $this->seedOrder();
    Order::$defaults = [
        'exclude' => ['external_ref'],
        'paths' => ['items' => ['exclude' => ['external_id'], 'with' => ['state' => 'default', 'title' => 'default']]],
    ];

    $copy = $order->deepReplicate('items')
        ->exclude(['slug'])
        ->configure('items', function ($rule) {
            $rule->with(['state' => 'builder']);
        })
        ->replicate()
        ->model();

    expect($copy->external_ref)->toBe('EXT-1');
    expect($copy->items()->pluck('state')->all())->toBe(['builder', 'builder']);
    expect($copy->items()->pluck('title')->all())->toBe(['default', 'default']);
    expect($copy->items()->pluck('external_id')->all())->toBe([null, null]);
});
