<?php

use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingRelationshipRuleException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsupportedReuseException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;

test('fluent facade', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)
        ->copy('items', 'items.options')
        ->reuse('items.product', 'customer')
        ->skip('payments')
        ->replicate();

    expect($result->model()->exists)->toBeTrue();
    expect($result->model()->getKey())->not->toBe($order->getKey());

    /** @var Order $copy */
    $copy = $result->model();
    expect($copy->items()->count())->toBe(2);
    expect($copy->items()->withCount('options')->get()->sum('options_count'))->toBe(3);
    expect($copy->payments()->count())->toBe(0);
    expect($copy->customer_id)->toBe($order->customer_id);
    expect($copy->items()->orderBy('id')->pluck('product_id')->all())->toBe($order->items()->orderBy('id')->pluck('product_id')->all());
});

test('trait with array', function () {
    $order = $this->seedOrder();

    $result = $order->deepReplicate([
        'items' => 'copy',
        'items.options' => 'copy',
        'items.product' => 'reuse',
        'customer' => 'reuse',
        'payments' => 'skip',
    ])->replicate();

    expect($result->stats()->copiedModels())->toBe(6);
    expect($result->stats()->reusedModels())->toBe(3);
});

test('trait with fluent configuration', function () {
    $order = $this->seedOrder();

    $result = $order->deepReplicate('items')
        ->copy('items.options')
        ->reuse('items.product', 'customer')
        ->configure('items', function (RelationRule $rule): void {
            $rule->exclude(['external_id']);
        })
        ->with(['status' => 'draft'])
        ->replicate();

    $copy = $result->model();
    expect($copy->status)->toBe('draft');
    expect($copy->items()->pluck('external_id')->all())->toBe([null, null]);
    expect($order->fresh()->status)->toBe('paid');
});

test('intentional failures', function () {
    $order = $this->seedOrder();
    $user = $this->seedBlog()['author'];
    $before = $this->counts();

    $this->assertThrows(\ArgumentCountError::class, function () use ($order) {
        // @phpstan-ignore-next-line
        $order->deepReplicate();
    });
    $this->assertThrows(EmptyRelationshipSelectionException::class, function () use ($order) {
        $order->deepReplicate([]);
    });
    $this->assertThrows(EmptyRelationshipSelectionException::class, function () use ($order) {
        DeepReplicate::for($order)->replicate();
    });
    $this->assertThrows(UnsupportedReuseException::class, function () use ($user) {
        DeepReplicate::for($user)->reuse('posts')->replicate();
    });
    $this->assertThrows(ConflictingRelationshipRuleException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->reuse('items')->replicate();
    });
    $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order) {
        DeepReplicate::for($order)->skip('items')->copy('items.options')->replicate();
    });

    expect($this->counts())->toBe($before);
});

test('default foreign key retention', function () {
    $post = $this->seedBlog()['post'];

    $new = DeepReplicate::for($post)->copy('comments')->replicate()->model();

    expect($new->author_id)->toBe($post->author_id);
});

test('new owner with the same related tags', function () {
    $blog = $this->seedBlog();
    $post = $blog['post'];
    $tags = $this->rows('tags');
    $pivots = $this->rows('taggables');

    $result = DeepReplicate::for($post)->reuse('tags')->replicate();

    expect($result->model()->tags()->orderBy('tags.id')->pluck('tags.id')->all())->toEqual($post->tags()->orderBy('tags.id')->pluck('tags.id')->all());
    expect($this->rows('tags'))->toBe($tags);
    expect(array_slice($this->rows('taggables'), 0, count($pivots)))->toBe($pivots);
    expect($post->tags()->count())->toBe(2);
});

test('fresh and loaded distinction', function () {
    $post = $this->seedBlog()['post'];
    $post->load(['comments' => function ($q) {
        $q->where('approved', true);
    }]);

    $all = DeepReplicate::for($post)->copy('comments')->replicate();
    $subset = DeepReplicate::for($post)->copy('comments')->usingLoaded('comments')->replicate();

    expect($all->model()->comments()->count())->toBe(2);
    expect($subset->model()->comments()->count())->toBe(1);
});

test('inspection', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $plan = DeepReplicate::for($order)
        ->copy('items', 'items.options')
        ->reuse('items.product')
        ->dryRun();

    expect($this->counts())->toBe($before);
    expect($plan->stats()->copiedModels())->toBe(6);

    $result = DeepReplicate::for($order)
        ->copy('items', 'items.options')
        ->reuse('items.product')
        ->replicate();

    $replica = $result->model();
    $mapping = $result->map();
    $stats = $result->stats();

    expect($replica->exists)->toBeTrue();
    expect($mapping->all())->toHaveCount(6);
    expect($stats->copiedModels())->toBe($plan->stats()->copiedModels());
    expect($stats->reusedModels())->toBe($plan->stats()->reusedModels());
});
