<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingIdentityStrategyException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingNodeConfigurationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\InvalidRelationshipPathException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnresolvableForeignKeyCycleException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Activity;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Comment;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemOption;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Post;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Product;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\StrictTeam;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\StrictUser;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Team;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\User;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class NestedGraphTest extends TestCase
{
    public function test_only_selected_paths_are_copied(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $result = DeepReplicate::for($order)->copy('items.options')->replicate();
        $copy = $result->model();
        $after = $this->counts();

        $this->assertSame($before['orders'] + 1, $after['orders']);
        $this->assertSame($before['order_items'] + 2, $after['order_items']);
        $this->assertSame($before['item_options'] + 3, $after['item_options']);
        $this->assertSame($before['payments'], $after['payments']);
        $this->assertSame($before['order_item_tag'], $after['order_item_tag']);
        $this->assertSame($before['products'], $after['products']);

        foreach ($copy->items as $item) {
            $this->assertEquals($copy->id, $item->order_id);

            foreach ($item->options as $option) {
                $this->assertEquals($item->id, $option->order_item_id);
            }
        }

        $this->assertSame(['Warm white', 'Dimmer', 'Oak'], ItemOption::query()->whereIn('order_item_id', $copy->items->modelKeys())->orderBy('id')->pluck('name')->all());
    }

    public function test_reused_products_are_left_untouched_while_options_are_copied(): void
    {
        $order = $this->seedOrder();
        $products = $this->rows('products');

        $result = DeepReplicate::for($order)->copy('items', 'items.options')->reuse('items.product')->replicate();

        $this->assertSame($products, $this->rows('products'));
        $this->assertSame(2, $result->stats()->reusedModels());
        $this->assertEquals(
            $order->items()->orderBy('id')->pluck('product_id')->all(),
            $result->model()->items()->orderBy('id')->pluck('product_id')->all()
        );
    }

    public function test_a_record_reached_through_two_copy_paths_is_copied_once(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)->copy('items', 'activeItems', 'items.options', 'activeItems.options')->replicate();
        $copy = $result->model();

        $this->assertSame(1 + 2 + 3, $result->stats()->copiedModels());
        $this->assertSame(2, $copy->items()->count());
        $this->assertSame(1, $copy->activeItems()->count());
        $this->assertSame(6, ItemOption::count());
    }

    public function test_a_cycle_back_to_the_root_resolves_to_the_new_root(): void
    {
        $blog = $this->seedBlog();
        $post = $blog['post'];

        $result = DeepReplicate::for($post)
            ->copy('author', 'author.posts')
            ->generate('title', function () {
                return 'First (copy)';
            })
            ->configure('author', function (RelationRule $rule) {
                $rule->with(['email' => 'author-copy@example.test']);
            })
            ->replicate();

        $copy = $result->model();
        $author = $copy->author;

        $this->assertSame(2, User::count());
        $this->assertSame(4, Post::count());
        $this->assertSame(1 + 1 + 1, $result->stats()->copiedModels());
        $this->assertNotEquals($blog['author']->id, $author->id);
        $this->assertSame(['First (copy)', 'Second'], $author->posts()->orderBy('id')->pluck('title')->all());
        $this->assertSame($copy->getKey(), $author->posts()->orderBy('id')->first()->getKey());
        $this->assertSame(2, $blog['author']->posts()->count());
    }

    public function test_a_record_copied_and_reused_in_one_operation_is_rejected_before_writing(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $exception = $this->assertThrown(ConflictingIdentityStrategyException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items.product')->reuse('activeItems.product')->replicate();
        });

        $this->assertStringContainsString(Product::class, $exception->getMessage());
        $this->assertStringContainsString('[items.product]', $exception->getMessage());
        $this->assertStringContainsString('[activeItems.product]', $exception->getMessage());
        $this->assertSame($before, $this->counts());
    }

    public function test_a_record_copied_with_two_different_configurations_is_rejected(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $this->assertThrown(ConflictingNodeConfigurationException::class, function () use ($order) {
            DeepReplicate::for($order)
                ->copy('items', 'activeItems')
                ->configure('items', function (RelationRule $rule) {
                    $rule->with(['state' => 'a']);
                })
                ->configure('activeItems', function (RelationRule $rule) {
                    $rule->with(['state' => 'b']);
                })
                ->replicate();
        });

        $this->assertSame($before, $this->counts());
    }

    public function test_a_configuration_set_on_one_path_applies_to_the_shared_copy(): void
    {
        $order = $this->seedOrder();

        $copy = DeepReplicate::for($order)
            ->copy('items', 'activeItems')
            ->configure('activeItems', function (RelationRule $rule) {
                $rule->with(['state' => 'pending']);
            })
            ->replicate()
            ->model();

        $this->assertSame('pending', $copy->items()->where('title', 'Lamp')->value('state'));
        $this->assertSame('shipped', $copy->items()->where('title', 'Desk')->value('state'));
    }

    public function test_a_nullable_foreign_key_cycle_is_broken_and_resolved(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => 'owner@example.test']);
        $team = Team::create(['owner_id' => $user->id, 'name' => 'Core']);
        $user->update(['current_team_id' => $team->id]);

        $result = DeepReplicate::for($user->fresh())
            ->copy('currentTeam', 'currentTeam.owner')
            ->with(['email' => 'copy@example.test'])
            ->replicate();

        $newUser = $result->model()->fresh();
        $newTeam = $newUser->currentTeam;

        $this->assertSame(2, User::count());
        $this->assertSame(2, Team::count());
        $this->assertNotEquals($team->id, $newTeam->id);
        $this->assertEquals($newUser->id, $newTeam->owner_id);
        $this->assertEquals($newTeam->id, $result->model()->current_team_id);
        $this->assertEquals($team->id, $user->fresh()->current_team_id);
    }

    public function test_a_non_nullable_foreign_key_cycle_fails_and_persists_nothing(): void
    {
        StrictUser::query()->insert(['id' => 1, 'strict_team_id' => 1, 'name' => 'Owner']);
        StrictTeam::query()->insert(['id' => 1, 'owner_id' => 1, 'name' => 'Core']);
        $before = $this->counts();

        $exception = $this->assertThrown(UnresolvableForeignKeyCycleException::class, function () {
            DeepReplicate::for(StrictUser::findOrFail(1))->copy('team', 'team.owner')->replicate();
        });

        $this->assertStringContainsString('strict_team_id', $exception->getMessage());
        $this->assertNotNull($exception->getPrevious());
        $this->assertSame($before, $this->counts());
    }

    public function test_a_nested_path_through_morph_to_must_exist_on_every_encountered_type(): void
    {
        $blog = $this->seedBlog();
        Activity::create(['subject_id' => $blog['post']->id, 'subject_type' => Post::class, 'description' => 'posted']);
        $videoActivity = Activity::create(['subject_id' => $blog['video']->id, 'subject_type' => get_class($blog['video']), 'description' => 'uploaded']);
        $before = $this->counts();

        $exception = $this->assertThrown(InvalidRelationshipPathException::class, function () use ($videoActivity) {
            DeepReplicate::for($videoActivity)->copy('subject', 'subject.comments')->replicate();
        });

        $this->assertStringContainsString('Video', $exception->getMessage());
        $this->assertStringContainsString('[subject.comments]', $exception->getMessage());
        $this->assertSame($before, $this->counts());

        $postActivity = Activity::query()->where('description', 'posted')->firstOrFail();
        $result = DeepReplicate::for($postActivity)->copy('subject', 'subject.comments', 'subject.images')->replicate();

        $this->assertSame(1 + 1 + 2 + 2, $result->stats()->copiedModels());
        $this->assertSame(2, Comment::query()->where('post_id', $result->model()->subject_id)->count());
    }

    public function test_an_unknown_morph_type_is_reported_with_the_path(): void
    {
        $activity = Activity::create(['subject_id' => 1, 'subject_type' => 'missing-type', 'description' => 'broken']);

        $exception = $this->assertThrown(InvalidRelationshipPathException::class, function () use ($activity) {
            DeepReplicate::for($activity)->copy('subject')->replicate();
        });

        $this->assertStringContainsString('[missing-type]', $exception->getMessage());
    }

    public function test_self_referencing_cycles_through_relations_terminate(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)->copy('items', 'items.order', 'items.order.items')->replicate();

        $this->assertSame(3, $result->stats()->copiedModels());
        $this->assertSame(2, Order::count());
        $this->assertSame(4, OrderItem::count());
        $this->assertSame(2, $result->model()->items()->count());
        $this->assertSame(3, $result->stats()->maxDepthReached());
    }

    public function test_dependency_order_inserts_copied_parents_first(): void
    {
        $order = $this->seedOrder();
        $tables = [];

        DB::listen(function ($query) use (&$tables) {
            if (preg_match('/^insert into ["`]?(\w+)["`]?/i', $query->sql, $match)) {
                $tables[] = $match[1];
            }
        });

        DeepReplicate::for($order)
            ->copy('items.options', 'items.product', 'customer')
            ->configure('items.product', function (RelationRule $rule) {
                $rule->generate('sku', function (Product $product) {
                    return $product->sku.'-copy';
                });
            })
            ->replicate();

        $this->assertLessThan(array_search('orders', $tables, true), array_search('customers', $tables, true));
        $this->assertLessThan(array_search('order_items', $tables, true), array_search('products', $tables, true));
        $this->assertLessThan(array_search('item_options', $tables, true), array_search('order_items', $tables, true));
    }
}
