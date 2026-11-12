<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationLimitExceededException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\ReplicationMap;
use Pharaonic\Laravel\DeepReplicate\ReplicationPlan;
use Pharaonic\Laravel\DeepReplicate\ReplicationStats;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemOption;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Product;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class DryRunAndResultsTest extends TestCase
{
    public function test_dry_run_is_read_only_and_runs_no_events_or_callbacks(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();
        $events = $this->recordModelEvents([Order::class, OrderItem::class, ItemOption::class, Product::class]);
        $called = [];
        $record = function (string $name) use (&$called) {
            return function () use (&$called, $name) {
                $called[] = $name;

                return [];
            };
        };

        $writes = $this->captureWrites(function () use ($order, $record, &$plan) {
            $plan = DeepReplicate::for($order)
                ->copy('items', 'items.options')
                ->reuse('items.product', 'customer')
                ->reuse('items.plainTags')
                ->skip('payments')
                ->generate('slug', $record('generate'))
                ->transform($record('transform'))
                ->transformPivot('items.plainTags', $record('transformPivot'))
                ->configure('items', function (RelationRule $rule) use ($record) {
                    $rule->generate('sku', $record('item generate'));
                })
                ->beforeReplicate($record('beforeReplicate'))
                ->beforeModel($record('beforeModel'))
                ->afterModel($record('afterModel'))
                ->afterReplicate($record('afterReplicate'))
                ->afterCommit($record('afterCommit'))
                ->dryRun();
        });

        $this->assertInstanceOf(ReplicationPlan::class, $plan);
        $this->assertSame([], $writes);
        $this->assertSame([], $called);
        $this->assertSame([], (array) $events);
        $this->assertSame($before, $this->counts());
        $this->assertFalse(method_exists($plan, 'model'));

        $this->assertSame(1 + 2 + 3, $plan->stats()->copiedModels());
        $this->assertSame(2 + 1 + 2, $plan->stats()->reusedModels());
        $this->assertSame(11, $plan->estimatedModels());
        $this->assertSame(3, $plan->estimatedPivotRows());
        $this->assertSame(1, $plan->stats()->skippedRelationships());
        $this->assertSame(2, $plan->stats()->maxDepthReached());
        $this->assertSame([
            'items' => 'copy',
            'items.options' => 'copy',
            'items.product' => 'reuse',
            'items.plainTags' => 'reuse',
            'customer' => 'reuse',
            'payments' => 'skip',
        ], $plan->relationships());
        $this->assertSame([], $plan->warnings());
    }

    public function test_dry_run_estimates_match_the_replication(): void
    {
        $order = $this->seedOrder();
        $build = function () use ($order) {
            return DeepReplicate::for($order)->copy('items', 'items.options')->reuse('items.product', 'items.plainTags');
        };

        $plan = $build()->dryRun()->stats()->toArray();
        $actual = $build()->replicate()->stats()->toArray();

        unset($plan['durationMs'], $actual['durationMs']);

        $this->assertSame($plan, $actual);
    }

    public function test_the_result_exposes_the_root_the_map_and_the_stats(): void
    {
        $order = $this->seedOrder();
        $items = $order->items()->orderBy('id')->get();
        $product = $items->first()->product;

        $result = DeepReplicate::for($order)->copy('items')->reuse('items.product', 'customer')->replicate();
        $map = $result->map();

        $this->assertTrue($result->model()->exists);
        $this->assertNotEquals($order->getKey(), $result->model()->getKey());
        $this->assertInstanceOf(ReplicationMap::class, $map);
        $this->assertSame($result->model(), $map->get($order));

        foreach ($items as $item) {
            $copy = $map->get($item);
            $this->assertInstanceOf(OrderItem::class, $copy);
            $this->assertTrue($copy->exists);
            $this->assertEquals($result->model()->getKey(), $copy->order_id);
        }

        $this->assertNull($map->get($product));
        $this->assertFalse($map->has($product));
        $this->assertTrue($map->isReused($product));
        $this->assertTrue($map->isReused($order->customer));
        $this->assertNull($map->get(new Product));
        $this->assertCount(3, $map->all());
        $this->assertCount(3, $map->reused());
        $this->assertSame('testing|'.Order::class.'|'.gettype($order->getKey()).':'.$order->getKey(), array_keys($map->all())[0]);

        $stats = $result->stats();
        $this->assertInstanceOf(ReplicationStats::class, $stats);
        $this->assertSame(3, $stats->copiedModels());
        $this->assertSame(3, $stats->reusedModels());
        $this->assertSame(0, $stats->pivotRowsCreated());
        $this->assertSame(0, $stats->skippedRelationships());
        $this->assertSame(2, $stats->maxDepthReached());
        $this->assertGreaterThan(0, $stats->durationMs());
    }

    public function test_a_shared_reused_record_counts_once(): void
    {
        $order = $this->seedOrder();
        $product = Product::query()->firstOrFail();
        DB::table('order_items')->update(['product_id' => $product->id]);

        $result = DeepReplicate::for($order)->copy('items')->reuse('items.product')->replicate();

        $this->assertSame(1, $result->stats()->reusedModels());
        $this->assertCount(1, $result->map()->reused());
    }

    public function test_the_identity_map_keeps_integer_and_string_keys_apart(): void
    {
        $order = $this->seedOrder();
        $result = DeepReplicate::for($order)->copy('items')->replicate();

        $twin = $order->newInstance([], true);
        $twin->setRawAttributes(['id' => (string) $order->getKey()]);
        $twin->setKeyType('string');

        $this->assertNotNull($result->map()->get($order));
        $this->assertNull($result->map()->get($twin));
    }

    public function test_max_depth_is_enforced_before_any_query(): void
    {
        $order = $this->seedOrder();
        $queries = 0;

        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $exception = $this->assertThrown(ReplicationLimitExceededException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items.options')->maxDepth(1)->replicate();
        });

        $this->assertStringContainsString('[items.options]', $exception->getMessage());
        $this->assertSame(0, $queries);
        $this->assertSame(1, Order::count());
        $this->assertSame(6, DeepReplicate::for($order)->copy('items.options')->maxDepth(2)->replicate()->stats()->copiedModels());
    }

    public function test_max_models_counts_copies_and_reused_records_including_the_root(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $writes = $this->captureWrites(function () use ($order) {
            $this->assertThrown(ReplicationLimitExceededException::class, function () use ($order) {
                DeepReplicate::for($order)->copy('items')->reuse('customer')->maxModels(3)->replicate();
            });
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->counts());
        $this->assertSame(3, DeepReplicate::for($order)->copy('items')->reuse('customer')->maxModels(4)->replicate()->stats()->copiedModels());
    }

    public function test_chunk_bounds_the_parents_of_each_relation_query(): void
    {
        $order = $this->seedOrder();
        $selects = function (int $chunk) use ($order): int {
            $count = 0;
            DB::listen(function ($query) use (&$count) {
                if (preg_match('/^select .* from ["`]?item_options["`]?/i', $query->sql)) {
                    $count++;
                }
            });
            DeepReplicate::for($order)->copy('items.options')->chunk($chunk)->dryRun();

            return $count;
        };

        $this->assertSame(1, $selects(500));
        $this->assertSame(2, $selects(1));
    }
}
