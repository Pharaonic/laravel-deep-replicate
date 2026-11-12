<?php

use Illuminate\Support\Str;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\ReplicationResult;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Customer;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Product;

/**
 * The README and docs/ examples, run against the test fixtures.
 */
test('readme and quick look example', function () {
    $order = $this->seedOrder();
    $oldItem = $order->items()->orderBy('id')->firstOrFail();

    $result = DeepReplicate::for($order)
        ->copy('items', 'items.options')
        ->reuse('items.product', 'customer')
        ->skip('payments')
        ->with(['status' => 'draft'])
        ->replicate();

    expect($result->model()->status)->toBe('draft');
    expect($result->map()->get($oldItem)->title)->toBe($oldItem->title);
    expect($result->stats()->copiedModels())->toBe(1 + 2 + 3);
});

test('order template recipe', function () {
    $order = $this->seedOrder('order-a-100');

    $copy = $order->deepReplicate('items', 'items.options')
        ->reuse('items.product', 'customer')
        ->skip('payments')
        ->with(['status' => 'draft'])
        ->generate('slug', function () {
            return 'order-'.Str::lower(Str::random(10));
        })
        ->configure('items', function (RelationRule $rule) {
            $rule->exclude(['external_id'])->with(['state' => 'pending']);
        })
        ->replicate()
        ->model();

    expect($copy->slug)->toStartWith('order-');
    expect($copy->slug)->not->toBe('order-a-100');
    expect($copy->items()->distinct()->pluck('state')->all())->toBe(['pending']);
    expect($copy->payments()->count())->toBe(0);
});

test('shared taxonomy recipe', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)
        ->copy('items', 'items.options')
        ->reuse('items.plainTags')
        ->configure('items', function (RelationRule $rule) {
            $rule->generate('sku', function () {
                return null;
            });
        })
        ->replicate();

    expect($result->stats()->pivotRowsCreated())->toBe(3);
    expect($result->stats()->reusedModels())->toBe(2);
});

test('translation recipe with after commit', function () {
    $post = $this->seedBlog()['post'];
    $dispatched = [];

    $copy = DeepReplicate::for($post)
        ->copy('comments', 'images')
        ->reuse('tags', 'author')
        ->with(['title' => 'Premier'])
        ->afterCommit(function (ReplicationResult $result) use (&$dispatched) {
            $dispatched[] = $result->model()->getKey();
        })
        ->replicate()
        ->model();

    expect($dispatched)->toBe([$copy->getKey()]);
    expect($copy->title)->toBe('Premier');
    expect($copy->author_id)->toBe($post->author_id);
    expect($copy->tags()->count())->toBe(2);
});

test('check before running recipe', function () {
    $order = $this->seedOrder();
    $builder = function () use ($order) {
        return DeepReplicate::for($order)->copy('items', 'items.options')->reuse('items.product');
    };

    $plan = $builder()->dryRun();

    expect($plan->estimatedModels())->toBe(8);
    expect($builder()->maxModels(8)->replicate()->stats()->copiedModels())->toBe(6);
});

test('tenant recipe sets the unmentioned key explicitly', function () {
    $order = $this->seedOrder();
    $target = Customer::create(['name' => 'Other tenant']);

    $same = DeepReplicate::for($order)->copy('items')->replicate()->model();
    $moved = DeepReplicate::for($order)->copy('items')->with(['customer_id' => $target->id])->replicate()->model();

    expect($same->customer_id)->toBe($order->customer_id);
    expect($moved->customer_id)->toEqual($target->id);
    expect(Product::count())->toBe(2);
    expect(Order::count())->toBe(3);
});

test('the trait and facade equivalence table', function () {
    $order = $this->seedOrder();
    $rows = [
        [$order->deepReplicate('items'), DeepReplicate::for($order)->copy('items')],
        [$order->deepReplicate('items', 'customer'), DeepReplicate::for($order)->copy('items', 'customer')],
        [$order->deepReplicate(['customer' => 'reuse']), DeepReplicate::for($order)->reuse('customer')],
        [$order->deepReplicate(['items', 'customer' => 'reuse']), DeepReplicate::for($order, ['items', 'customer' => 'reuse'])],
    ];

    foreach ($rows as $index => [$trait, $facade]) {
        $viaTrait = $trait->dryRun();
        $viaFacade = $facade->dryRun();

        $this->assertSame($viaFacade->relationships(), $viaTrait->relationships(), "row #{$index}");
        $this->assertSame($viaFacade->estimatedModels(), $viaTrait->estimatedModels(), "row #{$index}");
    }
});
