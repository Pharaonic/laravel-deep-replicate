<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\DeepReplicate\Exceptions\AfterCommitUnavailableException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\CrossConnectionReplicationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationCancelledException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\ReplicationContext;
use Pharaonic\Laravel\DeepReplicate\ReplicationResult;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemOption;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;
use RuntimeException;

class TransactionsAndEventsTest extends TestCase
{
    public function test_a_successful_replication_commits_once(): void
    {
        $order = $this->seedOrder();
        $levels = [];

        $result = DeepReplicate::for($order)->copy('items.options')->beforeReplicate(function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        })->replicate();

        $this->assertSame([1], $levels);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue(Order::query()->whereKey($result->model()->getKey())->exists());
    }

    public function test_a_failing_insert_leaves_no_new_records_or_pivots(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();
        $created = 0;

        ItemOption::creating(function () use (&$created) {
            if (++$created === 2) {
                throw new RuntimeException('second option fails');
            }
        });

        $exception = $this->assertThrown(RuntimeException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items.options', 'items.plainTags')->replicate();
        });

        $this->assertSame('second option fails', $exception->getMessage());
        $this->assertSame($before, $this->counts());
    }

    public function test_a_failing_pivot_insert_rolls_back_the_copied_models(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $this->assertThrown(RuntimeException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->reuse('items.plainTags')->transformPivot('items.plainTags', function () {
                throw new RuntimeException('pivot fails');
            })->replicate();
        });

        $this->assertSame($before, $this->counts());
    }

    public function test_a_cancelling_listener_rolls_back_and_reports_the_path(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        OrderItem::creating(function () {
            return false;
        });

        $exception = $this->assertThrown(ReplicationCancelledException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->replicate();
        });

        $this->assertStringContainsString('[items]', $exception->getMessage());
        $this->assertSame($before, $this->counts());
    }

    public function test_eloquent_events_fire_by_default(): void
    {
        $order = $this->seedOrder();
        $events = $this->recordModelEvents([Order::class, OrderItem::class]);

        DeepReplicate::for($order)->copy('items')->replicate();

        $sequence = (array) $events;
        $this->assertSame(['OrderItem.retrieved', 'OrderItem.retrieved'], array_slice($sequence, 0, 2));
        $this->assertSame(
            ['Order.replicating', 'OrderItem.replicating', 'OrderItem.replicating',
                'Order.saving', 'Order.creating', 'Order.created', 'Order.saved',
                'OrderItem.saving', 'OrderItem.creating', 'OrderItem.created', 'OrderItem.saved',
                'OrderItem.saving', 'OrderItem.creating', 'OrderItem.created', 'OrderItem.saved'],
            array_slice($sequence, 2)
        );
    }

    public function test_without_events_suppresses_every_model_event_and_restores_the_dispatcher(): void
    {
        $order = $this->seedOrder();
        $dispatcher = Model::getEventDispatcher();
        $events = $this->recordModelEvents([Order::class, OrderItem::class, ItemOption::class]);

        DeepReplicate::for($order)->copy('items.options')->withoutEvents()->replicate();

        $this->assertSame([], (array) $events);
        $this->assertSame($dispatcher, Model::getEventDispatcher());

        Order::query()->firstOrFail();
        $this->assertSame(['Order.retrieved'], (array) $events);
    }

    public function test_path_without_events_only_silences_that_path(): void
    {
        $order = $this->seedOrder();
        $events = $this->recordModelEvents([Order::class, OrderItem::class, ItemOption::class]);

        DeepReplicate::for($order)->copy('items.options')->configure('items', function (RelationRule $rule) {
            $rule->withoutEvents();
        })->replicate();

        $names = array_unique(array_map(function (string $event) {
            return explode('.', $event)[1] === 'retrieved' ? $event : explode('.', $event)[0].'.write';
        }, (array) $events));

        $this->assertContains('Order.write', $names);
        $this->assertContains('ItemOption.write', $names);
        $this->assertNotContains('OrderItem.write', $names);
        $this->assertSame(Model::getEventDispatcher(), $this->app['events']);
    }

    public function test_lifecycle_callbacks_fire_once_each_in_traversal_order(): void
    {
        $order = $this->seedOrder();
        $log = [];

        $result = DeepReplicate::for($order)
            ->copy('items')
            ->reuse('customer')
            ->beforeReplicate(function (ReplicationContext $context) use (&$log) {
                $log[] = 'beforeReplicate:'.$context->depth();
                $this->assertNull($context->replica());
            })
            ->beforeModel(function (Model $original, Model $copy, ReplicationContext $context) use (&$log) {
                $this->assertFalse($copy->exists);
                $log[] = 'beforeModel:'.class_basename($copy).':'.($context->path() ?? 'root');
            })
            ->afterModel(function (Model $original, Model $copy, ReplicationContext $context) use (&$log) {
                $this->assertTrue($copy->exists);
                $this->assertNotNull($context->replica());

                if ($copy instanceof OrderItem) {
                    $this->assertEquals($context->replica()->getKey(), $copy->order_id);
                }

                $log[] = 'afterModel:'.class_basename($copy).':'.$original->getKey();
            })
            ->afterReplicate(function (ReplicationResult $result, ReplicationContext $context) use (&$log) {
                $this->assertSame(1, DB::transactionLevel());
                $log[] = 'afterReplicate:'.$result->stats()->copiedModels();
            })
            ->afterCommit(function (ReplicationResult $result) use (&$log) {
                $this->assertSame(0, DB::transactionLevel());
                $log[] = 'afterCommit';
            })
            ->replicate();

        [$first, $second] = $order->items()->orderBy('id')->pluck('id')->all();

        $this->assertSame([
            'beforeReplicate:0',
            'beforeModel:Order:root',
            'beforeModel:OrderItem:items',
            'beforeModel:OrderItem:items',
            'afterModel:Order:'.$order->id,
            'afterModel:OrderItem:'.$first,
            'afterModel:OrderItem:'.$second,
            'afterReplicate:3',
            'afterCommit',
        ], $log);
        $this->assertTrue($result->model()->exists);
    }

    public function test_a_failing_pre_commit_callback_rolls_back(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        foreach (['beforeReplicate', 'beforeModel', 'afterModel', 'afterReplicate'] as $hook) {
            $committed = false;

            $this->assertThrown(RuntimeException::class, function () use ($order, $hook, &$committed) {
                DeepReplicate::for($order)->copy('items')->{$hook}(function () use ($hook) {
                    throw new RuntimeException($hook);
                })->afterCommit(function () use (&$committed) {
                    $committed = true;
                })->replicate();
            });

            $this->assertFalse($committed, $hook);
            $this->assertSame($before, $this->counts(), $hook);
        }
    }

    public function test_an_after_commit_failure_cannot_undo_the_commit(): void
    {
        $order = $this->seedOrder();

        $this->assertThrown(RuntimeException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->afterCommit(function () {
                throw new RuntimeException('after commit');
            })->replicate();
        });

        $this->assertSame(2, Order::count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_an_outer_transaction_rollback_discards_the_replica(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        DB::beginTransaction();
        $result = DeepReplicate::for($order)->copy('items.options')->replicate();
        $this->assertSame(2, Order::count());
        $this->assertSame(1, DB::transactionLevel());
        DB::rollBack();

        $this->assertSame($before, $this->counts());
        $this->assertFalse(Order::query()->whereKey($result->model()->getKey())->exists());
    }

    public function test_after_commit_never_fires_before_the_outer_transaction_commits(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();
        $fired = 0;
        $native = method_exists(Connection::class, 'afterCommit');

        DB::beginTransaction();

        try {
            DeepReplicate::for($order)->copy('items')->afterCommit(function () use (&$fired) {
                $fired++;
            })->replicate();

            $this->assertTrue($native, 'Only connections with a native afterCommit() may accept the callback.');
            $this->assertSame(0, $fired);
            DB::commit();
            $this->assertSame(1, $fired);
        } catch (AfterCommitUnavailableException $exception) {
            $this->assertFalse($native);
            $this->assertSame(0, $fired);
            $this->assertSame($before, $this->counts());
            $this->assertSame(1, DB::transactionLevel());
            DB::rollBack();
        }

        $this->assertSame($native ? 1 : 0, $fired);
    }

    public function test_after_commit_is_dropped_when_the_outer_transaction_rolls_back(): void
    {
        if (! method_exists(Connection::class, 'afterCommit')) {
            $order = $this->seedOrder();

            DB::beginTransaction();
            $this->assertThrown(AfterCommitUnavailableException::class, function () use ($order) {
                DeepReplicate::for($order)->copy('items')->afterCommit(function () {
                })->replicate();
            });
            DB::rollBack();

            $this->assertSame(1, Order::count());

            return;
        }

        $order = $this->seedOrder();
        $fired = false;

        DB::beginTransaction();
        DeepReplicate::for($order)->copy('items')->afterCommit(function () use (&$fired) {
            $fired = true;
        })->replicate();
        DB::rollBack();

        $this->assertFalse($fired);
        $this->assertSame(1, Order::count());
    }

    public function test_a_graph_spanning_connections_is_rejected_before_any_write(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $exception = $this->assertThrown(CrossConnectionReplicationException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items', 'notes')->replicate();
        });

        $this->assertStringContainsString('[secondary]', $exception->getMessage());
        $this->assertStringContainsString('[testing]', $exception->getMessage());
        $this->assertSame($before, $this->counts());
    }

    public function test_naming_the_default_connection_is_not_another_connection(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)->copy('localNotes')->replicate();

        $this->assertSame(1 + 2, $result->stats()->copiedModels());
    }

    public function test_replicate_plans_again_inside_its_transaction(): void
    {
        $order = $this->seedOrder();
        $builder = DeepReplicate::for($order)->copy('items');

        $this->assertSame(3, $builder->dryRun()->estimatedModels());

        OrderItem::create(['order_id' => $order->id, 'title' => 'Chair']);

        $result = $builder->replicate();

        $this->assertSame(4, $result->stats()->copiedModels());
        $this->assertSame(3, $result->model()->items()->count());
    }

    public function test_records_visible_inside_the_transaction_are_rolled_back_on_failure(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $this->assertThrown(RuntimeException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->afterReplicate(function (ReplicationResult $result) {
                $this->assertTrue(Order::query()->whereKey($result->model()->getKey())->exists());

                throw new RuntimeException('abort');
            })->replicate();
        });

        $this->assertSame($before, $this->counts());
    }
}
