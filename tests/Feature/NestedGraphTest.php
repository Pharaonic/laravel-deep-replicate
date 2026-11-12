<?php

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

test('only selected paths are copied', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $result = DeepReplicate::for($order)->copy('items.options')->replicate();
    $copy = $result->model();
    $after = $this->counts();

    expect($after['orders'])->toBe($before['orders'] + 1);
    expect($after['order_items'])->toBe($before['order_items'] + 2);
    expect($after['item_options'])->toBe($before['item_options'] + 3);
    expect($after['payments'])->toBe($before['payments']);
    expect($after['order_item_tag'])->toBe($before['order_item_tag']);
    expect($after['products'])->toBe($before['products']);

    foreach ($copy->items as $item) {
        expect($item->order_id)->toEqual($copy->id);

        foreach ($item->options as $option) {
            expect($option->order_item_id)->toEqual($item->id);
        }
    }

    expect(ItemOption::query()->whereIn('order_item_id', $copy->items->modelKeys())->orderBy('id')->pluck('name')->all())->toBe(['Warm white', 'Dimmer', 'Oak']);
});

test('reused products are left untouched while options are copied', function () {
    $order = $this->seedOrder();
    $products = $this->rows('products');

    $result = DeepReplicate::for($order)->copy('items', 'items.options')->reuse('items.product')->replicate();

    expect($this->rows('products'))->toBe($products);
    expect($result->stats()->reusedModels())->toBe(2);
    expect($result->model()->items()->orderBy('id')->pluck('product_id')->all())->toEqual($order->items()->orderBy('id')->pluck('product_id')->all());
});

test('a record reached through two copy paths is copied once', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)->copy('items', 'activeItems', 'items.options', 'activeItems.options')->replicate();
    $copy = $result->model();

    expect($result->stats()->copiedModels())->toBe(1 + 2 + 3);
    expect($copy->items()->count())->toBe(2);
    expect($copy->activeItems()->count())->toBe(1);
    expect(ItemOption::count())->toBe(6);
});

test('a cycle back to the root resolves to the new root', function () {
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

    expect(User::count())->toBe(2);
    expect(Post::count())->toBe(4);
    expect($result->stats()->copiedModels())->toBe(1 + 1 + 1);
    expect($author->id)->not->toEqual($blog['author']->id);
    expect($author->posts()->orderBy('id')->pluck('title')->all())->toBe(['First (copy)', 'Second']);
    expect($author->posts()->orderBy('id')->first()->getKey())->toBe($copy->getKey());
    expect($blog['author']->posts()->count())->toBe(2);
});

test('a record copied and reused in one operation is rejected before writing', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $exception = $this->assertThrows(ConflictingIdentityStrategyException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items.product')->reuse('activeItems.product')->replicate();
    });

    expect($exception->getMessage())->toContain(Product::class);
    expect($exception->getMessage())->toContain('[items.product]');
    expect($exception->getMessage())->toContain('[activeItems.product]');
    expect($this->counts())->toBe($before);
});

test('a record copied with two different configurations is rejected', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $this->assertThrows(ConflictingNodeConfigurationException::class, function () use ($order) {
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

    expect($this->counts())->toBe($before);
});

test('a configuration set on one path applies to the shared copy', function () {
    $order = $this->seedOrder();

    $copy = DeepReplicate::for($order)
        ->copy('items', 'activeItems')
        ->configure('activeItems', function (RelationRule $rule) {
            $rule->with(['state' => 'pending']);
        })
        ->replicate()
        ->model();

    expect($copy->items()->where('title', 'Lamp')->value('state'))->toBe('pending');
    expect($copy->items()->where('title', 'Desk')->value('state'))->toBe('shipped');
});

test('a nullable foreign key cycle is broken and resolved', function () {
    $user = User::create(['name' => 'Owner', 'email' => 'owner@example.test']);
    $team = Team::create(['owner_id' => $user->id, 'name' => 'Core']);
    $user->update(['current_team_id' => $team->id]);

    $result = DeepReplicate::for($user->fresh())
        ->copy('currentTeam', 'currentTeam.owner')
        ->with(['email' => 'copy@example.test'])
        ->replicate();

    $newUser = $result->model()->fresh();
    $newTeam = $newUser->currentTeam;

    expect(User::count())->toBe(2);
    expect(Team::count())->toBe(2);
    expect($newTeam->id)->not->toEqual($team->id);
    expect($newTeam->owner_id)->toEqual($newUser->id);
    expect($result->model()->current_team_id)->toEqual($newTeam->id);
    expect($user->fresh()->current_team_id)->toEqual($team->id);
});

test('a non nullable foreign key cycle fails and persists nothing', function () {
    StrictUser::query()->insert(['id' => 1, 'strict_team_id' => 1, 'name' => 'Owner']);
    StrictTeam::query()->insert(['id' => 1, 'owner_id' => 1, 'name' => 'Core']);
    $before = $this->counts();

    $exception = $this->assertThrows(UnresolvableForeignKeyCycleException::class, function () {
        DeepReplicate::for(StrictUser::findOrFail(1))->copy('team', 'team.owner')->replicate();
    });

    expect($exception->getMessage())->toContain('strict_team_id');
    expect($exception->getPrevious())->not->toBeNull();
    expect($this->counts())->toBe($before);
});

test('a nested path through morph to must exist on every encountered type', function () {
    $blog = $this->seedBlog();
    Activity::create(['subject_id' => $blog['post']->id, 'subject_type' => Post::class, 'description' => 'posted']);
    $videoActivity = Activity::create(['subject_id' => $blog['video']->id, 'subject_type' => get_class($blog['video']), 'description' => 'uploaded']);
    $before = $this->counts();

    $exception = $this->assertThrows(InvalidRelationshipPathException::class, function () use ($videoActivity) {
        DeepReplicate::for($videoActivity)->copy('subject', 'subject.comments')->replicate();
    });

    expect($exception->getMessage())->toContain('Video');
    expect($exception->getMessage())->toContain('[subject.comments]');
    expect($this->counts())->toBe($before);

    $postActivity = Activity::query()->where('description', 'posted')->firstOrFail();
    $result = DeepReplicate::for($postActivity)->copy('subject', 'subject.comments', 'subject.images')->replicate();

    expect($result->stats()->copiedModels())->toBe(1 + 1 + 2 + 2);
    expect(Comment::query()->where('post_id', $result->model()->subject_id)->count())->toBe(2);
});

test('an unknown morph type is reported with the path', function () {
    $activity = Activity::create(['subject_id' => 1, 'subject_type' => 'missing-type', 'description' => 'broken']);

    $exception = $this->assertThrows(InvalidRelationshipPathException::class, function () use ($activity) {
        DeepReplicate::for($activity)->copy('subject')->replicate();
    });

    expect($exception->getMessage())->toContain('[missing-type]');
});

test('self referencing cycles through relations terminate', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)->copy('items', 'items.order', 'items.order.items')->replicate();

    expect($result->stats()->copiedModels())->toBe(3);
    expect(Order::count())->toBe(2);
    expect(OrderItem::count())->toBe(4);
    expect($result->model()->items()->count())->toBe(2);
    expect($result->stats()->maxDepthReached())->toBe(3);
});

test('dependency order inserts copied parents first', function () {
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

    expect(array_search('customers', $tables, true))->toBeLessThan(array_search('orders', $tables, true));
    expect(array_search('products', $tables, true))->toBeLessThan(array_search('order_items', $tables, true));
    expect(array_search('order_items', $tables, true))->toBeLessThan(array_search('item_options', $tables, true));
});
