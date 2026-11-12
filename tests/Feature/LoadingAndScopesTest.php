<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\MissingLoadedRelationException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\PlanningWarning;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Comment;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemOption;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class LoadingAndScopesTest extends TestCase
{
    public function test_fresh_queries_ignore_a_preloaded_subset_and_using_loaded_keeps_it(): void
    {
        $post = $this->seedBlog()['post'];
        $post->load(['comments' => function ($query) {
            $query->where('approved', true);
        }]);

        $all = DeepReplicate::for($post)->copy('comments')->replicate()->model();
        $subset = DeepReplicate::for($post)->copy('comments')->usingLoaded('comments')->replicate()->model();

        $this->assertSame(['approved', 'pending'], $all->comments()->orderBy('id')->pluck('body')->all());
        $this->assertSame(['approved'], $subset->comments()->pluck('body')->all());
        $this->assertCount(1, $post->comments);
    }

    public function test_using_loaded_never_loads_a_missing_relation(): void
    {
        $post = $this->seedBlog()['post'];
        $before = $this->counts();

        $exception = $this->assertThrows(MissingLoadedRelationException::class, function () use ($post) {
            DeepReplicate::for($post)->copy('comments')->usingLoaded('comments')->replicate();
        });

        $this->assertStringContainsString('[comments]', $exception->getMessage());
        $this->assertFalse($post->relationLoaded('comments'));
        $this->assertSame($before, $this->counts());
    }

    public function test_nested_using_loaded_requires_the_relation_on_every_source_parent(): void
    {
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

        $this->assertSame(['Warm white', 'Oak'], ItemOption::query()->whereIn('order_item_id', $copy->items()->pluck('id'))->orderBy('id')->pluck('name')->all());
    }

    public function test_using_loaded_on_a_parent_does_not_imply_it_for_descendants(): void
    {
        $order = $this->seedOrder();
        $order->load(['items' => function ($query) {
            $query->where('title', 'Lamp');
        }, 'items.options' => function ($query) {
            $query->where('name', 'Dimmer');
        }]);

        $copy = DeepReplicate::for($order)->copy('items.options')->usingLoaded('items')->replicate()->model();

        $this->assertSame(['Lamp'], $copy->items()->pluck('title')->all());
        $this->assertSame(['Warm white', 'Dimmer'], $copy->items()->firstOrFail()->options()->orderBy('id')->pluck('name')->all());
    }

    public function test_filters_select_a_subset_without_touching_the_source(): void
    {
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

        $this->assertSame(2, $copy->items()->count());
        $this->assertSame(['Warm white', 'Oak'], ItemOption::query()->whereIn('order_item_id', $copy->items()->pluck('id'))->orderBy('id')->pluck('name')->all());
        $this->assertFalse($order->relationLoaded('items'));
        $this->assertSame(3, $order->items()->withCount('options')->get()->sum('options_count'));
    }

    public function test_a_filter_on_a_belongs_to_path_keeps_the_foreign_key_and_warns(): void
    {
        $order = $this->seedOrder();
        $filter = function ($query) {
            $query->where('name', 'nobody');
        };

        $plan = DeepReplicate::for($order)->copy('customer')->filter('customer', $filter)->dryRun();
        $copy = DeepReplicate::for($order)->copy('customer')->filter('customer', $filter)->replicate()->model();

        $this->assertSame(PlanningWarning::MISSING_TARGET, $plan->warnings()[0]->code());
        $this->assertSame('customer', $plan->warnings()[0]->path());
        $this->assertSame($order->customer_id, $copy->customer_id);
        $this->assertSame(1, $plan->estimatedModels());
    }

    public function test_filter_and_using_loaded_on_one_path_are_incompatible(): void
    {
        $order = $this->seedOrder()->load('items');

        $this->assertThrows(IncompatibleRelationOptionsException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->usingLoaded('items')->filter('items', function () {
            })->replicate();
        });

        $this->assertSame(1, Order::count());
    }

    public function test_global_scopes_are_honoured_by_default_and_removed_explicitly(): void
    {
        $order = $this->seedOrder();
        $order->items()->where('title', 'Desk')->firstOrFail()->delete();

        $default = DeepReplicate::for($order)->copy('items');
        $unscoped = DeepReplicate::for($order)->copy('items')->withoutGlobalScopes();

        $this->assertSame(2, $default->dryRun()->estimatedModels());
        $this->assertSame(3, $unscoped->dryRun()->estimatedModels());
        $this->assertSame(PlanningWarning::GLOBAL_SCOPES_REMOVED, $unscoped->dryRun()->warnings()[0]->code());

        $this->assertSame(1, $default->replicate()->model()->items()->count());
        $this->assertSame(2, $unscoped->replicate()->model()->items()->count());
    }

    public function test_relation_definition_constraints_are_followed(): void
    {
        $post = $this->seedBlog()['post'];

        $copy = DeepReplicate::for($post)->copy('approvedComments')->replicate()->model();

        $this->assertSame(['approved'], $copy->comments()->pluck('body')->all());
        $this->assertSame(4, Comment::count());
    }

    public function test_loaded_but_unselected_nested_relations_are_never_traversed(): void
    {
        $order = $this->seedOrder();
        $order->load('items.options', 'items.product', 'payments');

        $result = DeepReplicate::for($order)->copy('items')->usingLoaded('items')->replicate();

        $this->assertSame(3, $result->stats()->copiedModels());
        $this->assertSame(3, ItemOption::count());
        $this->assertSame(2, OrderItem::query()->where('order_id', $result->model()->id)->count());
    }
}
