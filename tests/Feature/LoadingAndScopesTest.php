<?php

use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\MissingLoadedRelationException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\PlanningWarning;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Comment;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemOption;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;

test('fresh queries ignore a preloaded subset and using loaded keeps it', function () {
    $post = $this->seedBlog()['post'];
    $post->load(['comments' => function ($query) {
        $query->where('approved', true);
    }]);

    $all = DeepReplicate::for($post)->copy('comments')->replicate()->model();
    $subset = DeepReplicate::for($post)->copy('comments')->usingLoaded('comments')->replicate()->model();

    expect($all->comments()->orderBy('id')->pluck('body')->all())->toBe(['approved', 'pending']);
    expect($subset->comments()->pluck('body')->all())->toBe(['approved']);
    expect($post->comments)->toHaveCount(1);
});

test('using loaded never loads a missing relation', function () {
    $post = $this->seedBlog()['post'];
    $before = $this->counts();

    $exception = $this->assertThrows(MissingLoadedRelationException::class, function () use ($post) {
        DeepReplicate::for($post)->copy('comments')->usingLoaded('comments')->replicate();
    });

    expect($exception->getMessage())->toContain('[comments]');
    expect($post->relationLoaded('comments'))->toBeFalse();
    expect($this->counts())->toBe($before);
});

test('nested using loaded requires the relation on every source parent', function () {
    $order = $this->seedOrder();

    $order->load('items');
    $order->items->first()->load('options');

    $this->assertThrows(MissingLoadedRelationException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items.options')->usingLoaded('items', 'items.options')->replicate();
    });

    $this->assertThrows(MissingLoadedRelationException::class, function () use ($order) {
        // Fresh items never carry loaded options.
        DeepReplicate::for($order)->copy('items.options')->usingLoaded('items.options')->replicate();
    });

    $order->load(['items.options' => function ($query) {
        $query->where('name', '!=', 'Dimmer');
    }]);

    $copy = DeepReplicate::for($order)->copy('items.options')->usingLoaded('items', 'items.options')->replicate()->model();

    expect(ItemOption::query()->whereIn('order_item_id', $copy->items()->pluck('id'))->orderBy('id')->pluck('name')->all())->toBe(['Warm white', 'Oak']);
});

test('using loaded on a parent does not imply it for descendants', function () {
    $order = $this->seedOrder();
    $order->load(['items' => function ($query) {
        $query->where('title', 'Lamp');
    }, 'items.options' => function ($query) {
        $query->where('name', 'Dimmer');
    }]);

    $copy = DeepReplicate::for($order)->copy('items.options')->usingLoaded('items')->replicate()->model();

    expect($copy->items()->pluck('title')->all())->toBe(['Lamp']);
    expect($copy->items()->firstOrFail()->options()->orderBy('id')->pluck('name')->all())->toBe(['Warm white', 'Dimmer']);
});

test('filters select a subset without touching the source', function () {
    $order = $this->seedOrder();

    $copy = DeepReplicate::for($order)
        ->copy('items.options')
        ->filter('items.options', function ($query) {
            $query->where('name', 'like', 'W%');
        })
        ->filter('items.options', function ($query) {
            $query->orWhere('name', 'Oak');
        })
        ->replicate()
        ->model();

    expect($copy->items()->count())->toBe(2);
    expect(ItemOption::query()->whereIn('order_item_id', $copy->items()->pluck('id'))->orderBy('id')->pluck('name')->all())->toBe(['Warm white', 'Oak']);
    expect($order->relationLoaded('items'))->toBeFalse();
    expect($order->items()->withCount('options')->get()->sum('options_count'))->toBe(3);
});

test('a filter on a belongs to path keeps the foreign key and warns', function () {
    $order = $this->seedOrder();
    $filter = function ($query) {
        $query->where('name', 'nobody');
    };

    $plan = DeepReplicate::for($order)->copy('customer')->filter('customer', $filter)->dryRun();
    $copy = DeepReplicate::for($order)->copy('customer')->filter('customer', $filter)->replicate()->model();

    expect($plan->warnings()[0]->code())->toBe(PlanningWarning::MISSING_TARGET);
    expect($plan->warnings()[0]->path())->toBe('customer');
    expect($copy->customer_id)->toBe($order->customer_id);
    expect($plan->estimatedModels())->toBe(1);
});

test('filter and using loaded on one path are incompatible', function () {
    $order = $this->seedOrder()->load('items');

    $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->usingLoaded('items')->filter('items', function () {
        })->replicate();
    });

    expect(Order::count())->toBe(1);
});

test('global scopes are honoured by default and removed explicitly', function () {
    $order = $this->seedOrder();
    $order->items()->where('title', 'Desk')->firstOrFail()->delete();

    $default = DeepReplicate::for($order)->copy('items');
    $unscoped = DeepReplicate::for($order)->copy('items')->withoutGlobalScopes();

    expect($default->dryRun()->estimatedModels())->toBe(2);
    expect($unscoped->dryRun()->estimatedModels())->toBe(3);
    expect($unscoped->dryRun()->warnings()[0]->code())->toBe(PlanningWarning::GLOBAL_SCOPES_REMOVED);

    expect($default->replicate()->model()->items()->count())->toBe(1);
    expect($unscoped->replicate()->model()->items()->count())->toBe(2);
});

test('relation definition constraints are followed', function () {
    $post = $this->seedBlog()['post'];

    $copy = DeepReplicate::for($post)->copy('approvedComments')->replicate()->model();

    expect($copy->comments()->pluck('body')->all())->toBe(['approved']);
    expect(Comment::count())->toBe(4);
});

test('loaded but unselected nested relations are never traversed', function () {
    $order = $this->seedOrder();
    $order->load('items.options', 'items.product', 'payments');

    $result = DeepReplicate::for($order)->copy('items')->usingLoaded('items')->replicate();

    expect($result->stats()->copiedModels())->toBe(3);
    expect(ItemOption::count())->toBe(3);
    expect(OrderItem::query()->where('order_id', $result->model()->id)->count())->toBe(2);
});
