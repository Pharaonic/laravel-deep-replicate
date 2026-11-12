<?php

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

test('dry run is read only and runs no events or callbacks', function () {
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

    expect($plan)->toBeInstanceOf(ReplicationPlan::class);
    expect($writes)->toBe([]);
    expect($called)->toBe([]);
    expect((array) $events)->toBe([]);
    expect($this->counts())->toBe($before);
    expect(method_exists($plan, 'model'))->toBeFalse();

    expect($plan->stats()->copiedModels())->toBe(1 + 2 + 3);
    expect($plan->stats()->reusedModels())->toBe(2 + 1 + 2);
    expect($plan->estimatedModels())->toBe(11);
    expect($plan->estimatedPivotRows())->toBe(3);
    expect($plan->stats()->skippedRelationships())->toBe(1);
    expect($plan->stats()->maxDepthReached())->toBe(2);
    expect($plan->relationships())->toBe([
        'items' => 'copy',
        'items.options' => 'copy',
        'items.product' => 'reuse',
        'items.plainTags' => 'reuse',
        'customer' => 'reuse',
        'payments' => 'skip',
    ]);
    expect($plan->warnings())->toBe([]);
});

test('dry run estimates match the replication', function () {
    $order = $this->seedOrder();
    $build = function () use ($order) {
        return DeepReplicate::for($order)->copy('items', 'items.options')->reuse('items.product', 'items.plainTags');
    };

    $plan = $build()->dryRun()->stats()->toArray();
    $actual = $build()->replicate()->stats()->toArray();

    unset($plan['durationMs'], $actual['durationMs']);

    expect($actual)->toBe($plan);
});

test('the result exposes the root the map and the stats', function () {
    $order = $this->seedOrder();
    $items = $order->items()->orderBy('id')->get();
    $product = $items->first()->product;

    $result = DeepReplicate::for($order)->copy('items')->reuse('items.product', 'customer')->replicate();
    $map = $result->map();

    expect($result->model()->exists)->toBeTrue();
    expect($result->model()->getKey())->not->toEqual($order->getKey());
    expect($map)->toBeInstanceOf(ReplicationMap::class);
    expect($map->get($order))->toBe($result->model());

    foreach ($items as $item) {
        $copy = $map->get($item);
        expect($copy)->toBeInstanceOf(OrderItem::class);
        expect($copy->exists)->toBeTrue();
        expect($copy->order_id)->toEqual($result->model()->getKey());
    }

    expect($map->get($product))->toBeNull();
    expect($map->has($product))->toBeFalse();
    expect($map->isReused($product))->toBeTrue();
    expect($map->isReused($order->customer))->toBeTrue();
    expect($map->get(new Product))->toBeNull();
    expect($map->all())->toHaveCount(3);
    expect($map->reused())->toHaveCount(3);
    expect(array_keys($map->all())[0])->toBe('testing|'.Order::class.'|'.gettype($order->getKey()).':'.$order->getKey());

    $stats = $result->stats();
    expect($stats)->toBeInstanceOf(ReplicationStats::class);
    expect($stats->copiedModels())->toBe(3);
    expect($stats->reusedModels())->toBe(3);
    expect($stats->pivotRowsCreated())->toBe(0);
    expect($stats->skippedRelationships())->toBe(0);
    expect($stats->maxDepthReached())->toBe(2);
    expect($stats->durationMs())->toBeGreaterThan(0);
});

test('a shared reused record counts once', function () {
    $order = $this->seedOrder();
    $product = Product::query()->firstOrFail();
    DB::table('order_items')->update(['product_id' => $product->id]);

    $result = DeepReplicate::for($order)->copy('items')->reuse('items.product')->replicate();

    expect($result->stats()->reusedModels())->toBe(1);
    expect($result->map()->reused())->toHaveCount(1);
});

test('the identity map keeps integer and string keys apart', function () {
    $order = $this->seedOrder();
    $result = DeepReplicate::for($order)->copy('items')->replicate();

    $twin = $order->newInstance([], true);
    $twin->setRawAttributes(['id' => (string) $order->getKey()]);
    $twin->setKeyType('string');

    expect($result->map()->get($order))->not->toBeNull();
    expect($result->map()->get($twin))->toBeNull();
});

test('max depth is enforced before any query', function () {
    $order = $this->seedOrder();
    $queries = 0;

    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $exception = $this->assertThrown(ReplicationLimitExceededException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items.options')->maxDepth(1)->replicate();
    });

    expect($exception->getMessage())->toContain('[items.options]');
    expect($queries)->toBe(0);
    expect(Order::count())->toBe(1);
    expect(DeepReplicate::for($order)->copy('items.options')->maxDepth(2)->replicate()->stats()->copiedModels())->toBe(6);
});

test('max models counts copies and reused records including the root', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $writes = $this->captureWrites(function () use ($order) {
        $this->assertThrown(ReplicationLimitExceededException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->reuse('customer')->maxModels(3)->replicate();
        });
    });

    expect($writes)->toBe([]);
    expect($this->counts())->toBe($before);
    expect(DeepReplicate::for($order)->copy('items')->reuse('customer')->maxModels(4)->replicate()->stats()->copiedModels())->toBe(3);
});

test('chunk bounds the parents of each relation query', function () {
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

    expect($selects(500))->toBe(1);
    expect($selects(1))->toBe(2);
});
