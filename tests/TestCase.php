<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Pharaonic\Laravel\DeepReplicate\DeepReplicateServiceProvider;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Comment;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Customer;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Image;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemOption;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemTag;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Payment;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Post;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Product;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Tag;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\User;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Video;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'sqlite') {
            // A server database outlives the process: start from an empty
            // schema even when an earlier run was interrupted.
            Schema::dropAllTables();
        }

        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');

        Order::$defaults = null;
        ItemTag::$events = [];
        Relation::$morphMap = [];
    }

    protected function tearDown(): void
    {
        Relation::$morphMap = [];

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [DeepReplicateServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['DeepReplicate' => DeepReplicate::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $driver = getenv('DB_CONNECTION') ?: 'sqlite';

        $connection = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]
            : [
                'driver' => $driver,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('DB_DATABASE') ?: 'deep_replicate',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
                'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
                'prefix' => '',
                'schema' => 'public',
                'sslmode' => 'prefer',
            ];

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);
        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * Order #1 for customer A: two items with options, one shared product,
     * tags with pivot data, payments.
     */
    protected function seedOrder(?string $slug = null): Order
    {
        $customer = Customer::create(['name' => 'Alice']);
        $productA = Product::create(['name' => 'Lamp', 'sku' => 'LAMP-1']);
        $productB = Product::create(['name' => 'Desk', 'sku' => 'DESK-1']);
        $red = Tag::create(['name' => 'red']);
        $blue = Tag::create(['name' => 'blue']);

        $order = new Order(['number' => 'a-100']);
        $order->customer_id = $customer->id;
        $order->status = 'paid';
        $order->slug = $slug;
        $order->external_ref = 'EXT-1';
        $order->meta = ['gift' => true, 'notes' => ['wrap']];
        $order->secret = 'top secret';
        $order->total = 99.5;
        $order->save();

        $first = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productA->id, 'title' => 'Lamp', 'external_id' => 'X1', 'state' => 'shipped', 'active' => true,
        ]);
        $second = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $productB->id, 'title' => 'Desk', 'external_id' => 'X2', 'state' => 'shipped', 'active' => false,
        ]);

        ItemOption::create(['order_item_id' => $first->id, 'name' => 'Warm white']);
        ItemOption::create(['order_item_id' => $first->id, 'name' => 'Dimmer']);
        ItemOption::create(['order_item_id' => $second->id, 'name' => 'Oak']);

        DB::table('order_item_tag')->insert([
            ['order_item_id' => $first->id, 'tag_id' => $red->id, 'primary' => true, 'weight' => 5, 'options' => '{"a":1}', 'note' => 'n1', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-02 00:00:00'],
            ['order_item_id' => $first->id, 'tag_id' => $blue->id, 'primary' => false, 'weight' => 1, 'options' => null, 'note' => null, 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-02 00:00:00'],
            ['order_item_id' => $second->id, 'tag_id' => $red->id, 'primary' => false, 'weight' => 2, 'options' => null, 'note' => 'n3', 'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-02 00:00:00'],
        ]);

        Payment::create(['order_id' => $order->id, 'amount' => 10]);
        Payment::create(['order_id' => $order->id, 'amount' => 20]);

        return $order->fresh();
    }

    /**
     * An author with two posts; the first post has comments, images, tags
     * (with pivot context) and an activity. A video shares a tag.
     *
     * @return array{author: User, post: Post, other: Post, video: Video, red: Tag, blue: Tag}
     */
    protected function seedBlog(): array
    {
        $author = User::create(['name' => 'Author', 'email' => 'author@example.test']);
        $post = Post::create(['author_id' => $author->id, 'title' => 'First']);
        $other = Post::create(['author_id' => $author->id, 'title' => 'Second']);
        $video = Video::create(['title' => 'Clip']);
        $red = Tag::create(['name' => 'red']);
        $blue = Tag::create(['name' => 'blue']);

        Comment::create(['post_id' => $post->id, 'body' => 'approved', 'approved' => true]);
        Comment::create(['post_id' => $post->id, 'body' => 'pending', 'approved' => false]);
        Comment::create(['post_id' => $other->id, 'body' => 'elsewhere', 'approved' => true]);

        Image::create(['imageable_id' => $post->id, 'imageable_type' => $post->getMorphClass(), 'url' => 'a.png', 'featured' => true]);
        Image::create(['imageable_id' => $post->id, 'imageable_type' => $post->getMorphClass(), 'url' => 'b.png', 'featured' => false]);
        Image::create(['imageable_id' => $video->id, 'imageable_type' => $video->getMorphClass(), 'url' => 'v.png', 'featured' => true]);

        DB::table('taggables')->insert([
            ['tag_id' => $red->id, 'taggable_id' => $post->id, 'taggable_type' => $post->getMorphClass(), 'context' => 'main'],
            ['tag_id' => $blue->id, 'taggable_id' => $post->id, 'taggable_type' => $post->getMorphClass(), 'context' => null],
            ['tag_id' => $red->id, 'taggable_id' => $video->id, 'taggable_type' => $video->getMorphClass(), 'context' => 'clip'],
        ]);

        return [
            'author' => $author->fresh(),
            'post' => $post->fresh(),
            'other' => $other->fresh(),
            'video' => $video->fresh(),
            'red' => $red->fresh(),
            'blue' => $blue->fresh(),
        ];
    }

    /**
     * Row counts of every fixture table that the given test may touch.
     *
     * @return array<string, int>
     */
    protected function counts(): array
    {
        $counts = [];

        foreach ([
            'customers', 'products', 'orders', 'order_items', 'item_options', 'payments', 'invoices', 'tags', 'order_item_tag',
            'order_tag_unique', 'users', 'teams', 'posts', 'comments', 'videos', 'images', 'taggables', 'activities',
            'documents', 'pages', 'tickets', 'legacy_shelves', 'legacy_books', 'legacy_authors', 'legacy_book_author',
            'strict_users', 'strict_teams',
        ] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * Raw rows of a table, ordered by id, for before/after comparisons.
     *
     * @return list<array<string, mixed>>
     */
    protected function rows(string $table, string $order = 'id'): array
    {
        return DB::table($table)->orderBy($order)->get()->map(function ($row) {
            return (array) $row;
        })->all();
    }

    /**
     * @return list<string>
     */
    protected function captureWrites(callable $callback): array
    {
        $writes = [];

        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $callback();

        return $writes;
    }

    /**
     * Listen to Eloquent model events and collect "Model.event" names.
     *
     * @param  list<class-string<Model>>  $models
     * @return \ArrayObject<int, string>
     */
    protected function recordModelEvents(array $models): \ArrayObject
    {
        $events = new \ArrayObject;

        foreach ($models as $model) {
            foreach (['retrieved', 'creating', 'created', 'saving', 'saved', 'updating', 'updated', 'replicating'] as $event) {
                $this->app['events']->listen("eloquent.{$event}: {$model}", function () use ($events, $event, $model) {
                    $events[] = class_basename($model).'.'.$event;
                });
            }
        }

        return $events;
    }

    /**
     * Assert that the callback throws, and return the exception.
     *
     * @template T of \Throwable
     *
     * @param  class-string<T>  $exception
     * @return T
     */
    protected function assertThrown(string $exception, callable $callback): \Throwable
    {
        try {
            $callback();
        } catch (\Throwable $thrown) {
            $this->assertInstanceOf($exception, $thrown, get_class($thrown).': '.$thrown->getMessage());

            return $thrown;
        }

        $this->fail("Expected {$exception} to be thrown.");
    }
}
