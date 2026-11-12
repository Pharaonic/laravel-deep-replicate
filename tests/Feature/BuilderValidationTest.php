<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use ArgumentCountError;
use InvalidArgumentException;
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
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class BuilderValidationTest extends TestCase
{
    public function test_the_trait_requires_a_first_argument(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        try {
            // @phpstan-ignore-next-line
            $order->deepReplicate();
            $this->fail('deepReplicate() without arguments must fail.');
        } catch (ArgumentCountError $error) {
            $this->assertStringContainsString('deepReplicate', $error->getMessage());
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_empty_selections_are_rejected_before_any_write(): void
    {
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
                    $this->assertStringContainsString(Order::class, $exception->getMessage());
                }
            });

            $this->assertSame([], $writes, $name);
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_a_skipped_nested_path_does_not_imply_its_parent(): void
    {
        $order = $this->seedOrder();

        $plan = DeepReplicate::for($order)->copy('customer')->skip('items.options')->dryRun();

        $this->assertSame(['customer' => 'copy', 'items.options' => 'skip'], $plan->relationships());
        $this->assertSame(2, $plan->stats()->copiedModels());
    }

    public function test_selection_syntaxes_produce_the_same_canonical_plan(): void
    {
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
    }

    public function test_the_facade_and_the_trait_build_equivalent_builders(): void
    {
        $order = $this->seedOrder();

        $viaFacade = DeepReplicate::for($order, 'items')->reuse('customer');
        $viaTrait = $order->deepReplicate('items')->reuse('customer');
        $viaService = $this->app->make(DeepReplicator::class)->for($order, ['items'])->reuse('customer');

        $this->assertInstanceOf(ReplicationBuilder::class, $viaFacade);
        $this->assertInstanceOf(ReplicationBuilder::class, $viaTrait);
        $this->assertEquals($viaFacade->dryRun()->stats()->toArray()['copiedModels'], $viaTrait->dryRun()->stats()->copiedModels());
        $this->assertSame($viaFacade->dryRun()->relationships(), $viaTrait->dryRun()->relationships());
        $this->assertSame($viaTrait->dryRun()->relationships(), $viaService->dryRun()->relationships());
        $this->assertSame(3, $viaTrait->replicate()->stats()->copiedModels());
        $this->assertSame(3, $viaFacade->replicate()->stats()->copiedModels());
    }

    public function test_the_trait_keeps_the_native_replicate_method(): void
    {
        $order = $this->seedOrder();

        $this->assertFalse(method_exists(DeepReplicates::class, 'replicate'));

        $clone = $order->replicate();

        $this->assertInstanceOf(Order::class, $clone);
        $this->assertFalse($clone->exists);
        $this->assertSame(1, Order::count());
    }

    public function test_repeating_a_strategy_is_idempotent(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)->copy('items')->copy('items')->relations(['items' => 'copy', 'items'])->replicate();

        $this->assertSame(3, $result->stats()->copiedModels());
    }

    public function test_conflicting_strategies_throw_regardless_of_order(): void
    {
        $order = $this->seedOrder();

        foreach ([['copy', 'reuse'], ['reuse', 'copy'], ['copy', 'skip'], ['skip', 'reuse']] as [$first, $second]) {
            try {
                DeepReplicate::for($order)->{$first}('items')->{$second}('items')->replicate();
                $this->fail("{$first} then {$second} must conflict.");
            } catch (ConflictingRelationshipRuleException $exception) {
                $this->assertStringContainsString('[items]', $exception->getMessage());
            }
        }

        $this->assertSame(1, Order::count());
    }

    public function test_a_nested_path_implies_copying_its_parents(): void
    {
        $order = $this->seedOrder();

        $plan = DeepReplicate::for($order)->copy('items.options')->dryRun();

        $this->assertSame(['items' => 'copy', 'items.options' => 'copy'], $plan->relationships());
        $this->assertSame(6, $plan->estimatedModels());
    }

    public function test_an_active_path_under_a_skipped_or_reused_parent_is_rejected(): void
    {
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
                $this->assertMatchesRegularExpression('/under \[(items|customer)\]/', $exception->getMessage());
            }
        }

        $this->assertSame(1, Order::count());
    }

    public function test_invalid_paths_and_strategies_are_rejected(): void
    {
        $order = $this->seedOrder();

        foreach (['', 'items..options', ' items', 'items.', '1items', 'items-x'] as $path) {
            try {
                DeepReplicate::for($order)->copy($path);
                $this->fail("path [{$path}] must be rejected.");
            } catch (InvalidRelationshipPathException $exception) {
                $this->assertStringContainsString('invalid', $exception->getMessage());
            }
        }

        $this->assertThrows(InvalidRelationshipPathException::class, function () use ($order) {
            DeepReplicate::for($order, [123]);
        });

        foreach (['clone', '', null, 1, true, ['copy'], function () {
        }] as $strategy) {
            $this->assertThrows(InvalidRelationshipStrategyException::class, function () use ($order, $strategy) {
                DeepReplicate::for($order, ['items' => $strategy]);
            });
        }
    }

    public function test_non_relation_methods_are_rejected_safely_with_model_and_path(): void
    {
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
                $this->assertStringContainsString("[{$path}]", $exception->getMessage());
                $this->assertStringContainsString(Order::class, $exception->getMessage());
                $this->assertStringContainsString($reason, $exception->getMessage(), $path);
            }
        }

        $this->assertSame($before, $this->counts());
        $this->assertNull($order->fresh()->deleted_at);
    }

    public function test_nested_invalid_paths_name_the_nested_model(): void
    {
        $order = $this->seedOrder();

        try {
            DeepReplicate::for($order)->copy('items.nothing')->dryRun();
            $this->fail('items.nothing must be rejected.');
        } catch (InvalidRelationshipPathException $exception) {
            $this->assertStringContainsString('[items.nothing]', $exception->getMessage());
            $this->assertStringContainsString('OrderItem', $exception->getMessage());
        }
    }

    public function test_options_must_target_selected_paths_with_a_compatible_strategy(): void
    {
        $order = $this->seedOrder();
        $noop = function () {
        };

        $this->assertThrows(InvalidRelationshipPathException::class, function () use ($order, $noop) {
            DeepReplicate::for($order)->copy('items')->filter('payments', $noop)->dryRun();
        });

        $this->assertThrows(InvalidRelationshipPathException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->usingLoaded('payments')->dryRun();
        });

        $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->reuse('customer')->configure('customer', function ($rule) {
                $rule->exclude(['name']);
            })->dryRun();
        });

        $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order, $noop) {
            DeepReplicate::for($order)->copy('items')->skip('payments')->filter('payments', $noop)->dryRun();
        });

        $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order, $noop) {
            DeepReplicate::for($order)->copy('items')->filter('items', $noop)->usingLoaded('items')->dryRun();
        });

        $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->copyPivot('items')->dryRun();
        });

        $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->transformPivot('items', function (array $attributes) {
                return $attributes;
            })->dryRun();
        });
    }

    public function test_limits_must_be_positive(): void
    {
        $builder = DeepReplicate::for($this->seedOrder());

        foreach (['maxDepth', 'maxModels', 'maxPivotRows', 'chunk'] as $method) {
            foreach ([0, -1] as $value) {
                $this->assertThrows(InvalidArgumentException::class, function () use ($builder, $method, $value) {
                    $builder->{$method}($value);
                });
            }

            $this->assertSame($builder, $builder->{$method}(1));
        }
    }

    public function test_builders_are_single_use(): void
    {
        $order = $this->seedOrder();
        $builder = DeepReplicate::for($order)->copy('items');

        $builder->dryRun();
        $builder->dryRun();
        $builder->replicate();

        $this->assertThrows(BuilderAlreadyExecutedException::class, function () use ($builder) {
            $builder->replicate();
        });
        $this->assertThrows(BuilderAlreadyExecutedException::class, function () use ($builder) {
            $builder->dryRun();
        });

        $failing = DeepReplicate::for($order)->copy('items')->beforeReplicate(function () {
            throw new \RuntimeException('stop');
        });

        $this->assertThrows(\RuntimeException::class, function () use ($failing) {
            $failing->replicate();
        });
        $this->assertThrows(BuilderAlreadyExecutedException::class, function () use ($failing) {
            $failing->replicate();
        });

        $this->assertSame(2, Order::count());
    }

    public function test_builders_do_not_share_state(): void
    {
        $order = $this->seedOrder();

        $first = DeepReplicate::for($order)->copy('items')->with(['status' => 'one']);
        $second = DeepReplicate::for($order)->copy('payments')->with(['status' => 'two']);

        $this->assertSame(['items' => 'copy'], $first->dryRun()->relationships());
        $this->assertSame(['payments' => 'copy'], $second->dryRun()->relationships());
        $this->assertSame('two', $second->replicate()->model()->status);
        $this->assertSame('one', $first->replicate()->model()->status);
    }

    public function test_an_unsaved_source_is_rejected(): void
    {
        $this->assertThrows(UnsavedSourceModelException::class, function () {
            DeepReplicate::for(new Order(['number' => 'x']))->copy('items')->replicate();
        });

        $this->assertSame(0, Order::count());
    }

    public function test_every_package_exception_extends_the_base_exception(): void
    {
        foreach (glob(__DIR__.'/../../src/Exceptions/*.php') ?: [] as $file) {
            $class = 'Pharaonic\\Laravel\\DeepReplicate\\Exceptions\\'.basename($file, '.php');

            $this->assertTrue(is_a($class, DeepReplicationException::class, true), $class);
            $this->assertTrue(is_a($class, \RuntimeException::class, true), $class);
        }
    }

    public function test_model_defaults_configure_selected_paths_only(): void
    {
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

        $this->assertSame('from-builder', $copy->status);
        $this->assertNull($copy->external_ref);
        $this->assertSame(['pending', 'pending'], $copy->items()->pluck('state')->all());
        $this->assertSame([null, null], $copy->items()->pluck('external_id')->all());
        $this->assertSame(0, $copy->payments()->count());
        $this->assertSame(2, $order->payments()->count());
    }

    public function test_model_defaults_cannot_select_paths_or_change_strategies(): void
    {
        $order = $this->seedOrder();

        Order::$defaults = ['paths' => ['customer' => ['with' => ['name' => 'changed']]]];
        $result = $order->deepReplicate('items')->reuse('customer')->replicate();
        $this->assertSame(0, $result->stats()->copiedModels() - 3);
        $this->assertSame('Alice', $result->model()->customer->name);

        Order::$defaults = ['paths' => ['items' => ['strategy' => 'reuse']]];
        $this->assertThrows(InvalidArgumentException::class, function () use ($order) {
            $order->deepReplicate('items')->dryRun();
        });

        Order::$defaults = ['relations' => ['items']];
        $this->assertThrows(InvalidArgumentException::class, function () use ($order) {
            $order->deepReplicate('customer')->dryRun();
        });
    }

    public function test_the_builder_configuration_wins_over_model_defaults_per_setting(): void
    {
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

        $this->assertSame('EXT-1', $copy->external_ref);
        $this->assertSame(['builder', 'builder'], $copy->items()->pluck('state')->all());
        $this->assertSame(['default', 'default'], $copy->items()->pluck('title')->all());
        $this->assertSame([null, null], $copy->items()->pluck('external_id')->all());
    }
}
