<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsupportedRelationshipException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsupportedReuseException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Activity;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Author;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Book;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Customer;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Image;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Invoice;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Post;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Shelf;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Tag;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Video;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class DirectRelationshipsTest extends TestCase
{
    public function test_has_one_copy_creates_a_new_child_for_the_new_parent(): void
    {
        $order = $this->seedOrder();
        $invoice = Invoice::create(['order_id' => $order->id, 'code' => 'INV-1']);

        $copy = DeepReplicate::for($order)->copy('invoice')->replicate()->model();

        $this->assertSame(2, Invoice::count());
        $this->assertSame('INV-1', $copy->invoice->code);
        $this->assertNotEquals($invoice->id, $copy->invoice->id);
        $this->assertEquals($order->id, $invoice->fresh()->order_id);
    }

    public function test_has_one_skip_leaves_the_child_alone(): void
    {
        $order = $this->seedOrder();
        Invoice::create(['order_id' => $order->id, 'code' => 'INV-1']);

        $copy = DeepReplicate::for($order)->copy('items')->skip('invoice')->replicate()->model();

        $this->assertSame(1, Invoice::count());
        $this->assertNull($copy->invoice);
    }

    public function test_reuse_of_child_relations_is_rejected_before_any_write(): void
    {
        $order = $this->seedOrder();
        Invoice::create(['order_id' => $order->id, 'code' => 'INV-1']);
        $blog = $this->seedBlog();
        $before = $this->counts();

        $cases = [
            'invoice' => [$order, 'HasOne'],
            'items' => [$order, 'HasMany'],
            'images' => [$order, 'MorphMany'],
            'featuredImage' => [$blog['post'], 'MorphOne'],
            'posts' => [$blog['author'], 'HasMany'],
        ];

        foreach ($cases as $path => [$model, $type]) {
            $exception = $this->assertThrown(UnsupportedReuseException::class, function () use ($model, $path) {
                DeepReplicate::for($model)->reuse($path)->replicate();
            });

            $this->assertStringContainsString("[{$path}]", $exception->getMessage());
            $this->assertStringContainsString($type, $exception->getMessage());
            $this->assertStringContainsString(get_class($model), $exception->getMessage());
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_has_many_copy_copies_every_child_and_filters_select_a_subset(): void
    {
        $order = $this->seedOrder();

        $all = DeepReplicate::for($order)->copy('items')->replicate()->model();
        $active = DeepReplicate::for($order)->copy('items')->filter('items', function ($query) {
            $query->where('active', true);
        })->replicate()->model();
        $scoped = DeepReplicate::for($order)->copy('activeItems')->replicate()->model();

        $this->assertSame(['Lamp', 'Desk'], $all->items()->orderBy('id')->pluck('title')->all());
        $this->assertSame(['Lamp'], $active->items()->pluck('title')->all());
        $this->assertSame(['Lamp'], $scoped->items()->pluck('title')->all());
        $this->assertSame(2, $order->items()->count());
    }

    public function test_belongs_to_copy_creates_the_parent_and_rewrites_the_foreign_key(): void
    {
        $order = $this->seedOrder();

        $copy = DeepReplicate::for($order)->copy('customer')->replicate()->model();

        $this->assertSame(2, Customer::count());
        $this->assertNotEquals($order->customer_id, $copy->customer_id);
        $this->assertSame('Alice', $copy->customer->name);
        $this->assertEquals($order->customer_id, $order->fresh()->customer_id);
    }

    public function test_belongs_to_reuse_keeps_the_foreign_key(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)->reuse('customer')->replicate();

        $this->assertSame(1, Customer::count());
        $this->assertSame($order->customer_id, $result->model()->customer_id);
        $this->assertSame(1, $result->stats()->reusedModels());
    }

    public function test_belongs_to_with_a_null_target_stays_null(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)->copy('assignedCustomer')->replicate();

        $this->assertNull($result->model()->assigned_customer_id);
        $this->assertSame(1, $result->stats()->copiedModels());
        $this->assertSame(1, Customer::count());
    }

    public function test_belongs_to_skip_and_unmentioned_keep_the_foreign_key(): void
    {
        $blog = $this->seedBlog();
        $post = $blog['post'];

        $skipped = DeepReplicate::for($post)->copy('comments')->skip('author')->replicate()->model();
        $unmentioned = DeepReplicate::for($post)->copy('comments')->replicate()->model();

        $this->assertSame($post->author_id, $skipped->author_id);
        $this->assertSame($post->author_id, $unmentioned->author_id);
    }

    public function test_belongs_to_many_copy_creates_targets_and_pivot_rows(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->first();
        $tags = Tag::count();

        $result = DeepReplicate::for($item)->copy('plainTags')->replicate();
        $copy = $result->model();

        $this->assertSame($tags + 2, Tag::count());
        $this->assertSame(2, $result->stats()->pivotRowsCreated());
        $this->assertSame(['blue', 'red'], $copy->plainTags()->orderBy('name')->pluck('name')->all());
        $this->assertEmpty(array_intersect($item->plainTags()->pluck('tags.id')->all(), $copy->plainTags()->pluck('tags.id')->all()));
    }

    public function test_belongs_to_many_reuse_links_the_same_targets_with_new_pivot_rows(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->first();
        $pivots = $this->rows('order_item_tag');

        $result = DeepReplicate::for($item)->reuse('plainTags')->replicate();
        $copy = $result->model();

        $this->assertSame(2, Tag::count());
        $this->assertSame(2, $result->stats()->reusedModels());
        $this->assertEquals($item->plainTags()->orderBy('tags.id')->pluck('tags.id')->all(), $copy->plainTags()->orderBy('tags.id')->pluck('tags.id')->all());
        $this->assertSame($pivots, array_slice($this->rows('order_item_tag'), 0, count($pivots)));
        $this->assertSame(count($pivots) + 2, DB::table('order_item_tag')->count());
    }

    public function test_belongs_to_many_skip_creates_no_pivot_rows(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->first();

        DeepReplicate::for($item)->copy('options')->skip('plainTags')->replicate();

        $this->assertSame(3, DB::table('order_item_tag')->count());
    }

    public function test_morph_one_and_morph_many_copies_get_the_new_morph_id_and_type(): void
    {
        $blog = $this->seedBlog();
        $post = $blog['post'];

        $copy = DeepReplicate::for($post)->copy('images')->replicate()->model();
        $featured = DeepReplicate::for($post)->copy('featuredImage')->replicate()->model();

        $this->assertSame(['a.png', 'b.png'], $copy->images()->orderBy('id')->pluck('url')->all());
        $this->assertSame([Post::class], $copy->images()->distinct()->pluck('imageable_type')->all());
        $this->assertSame('a.png', $featured->featuredImage->url);
        $this->assertEquals($featured->id, $featured->featuredImage->imageable_id);
        $this->assertSame(2, $post->images()->count());
        $this->assertSame(6, Image::count());
    }

    public function test_morph_many_uses_the_morph_map_alias(): void
    {
        Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
        $blog = $this->seedBlog();

        $copy = DeepReplicate::for($blog['post'])->copy('images')->replicate()->model();

        $this->assertSame(['post'], $copy->images()->distinct()->pluck('imageable_type')->all());
    }

    public function test_morph_to_copy_creates_the_concrete_target_and_updates_the_morph_id(): void
    {
        $blog = $this->seedBlog();
        $image = Image::query()->where('url', 'v.png')->firstOrFail();

        $result = DeepReplicate::for($image)->copy('imageable')->replicate();
        $copy = $result->model();

        $this->assertInstanceOf(Video::class, $copy->imageable);
        $this->assertNotEquals($blog['video']->id, $copy->imageable_id);
        $this->assertSame($image->imageable_type, $copy->imageable_type);
        $this->assertSame(2, Video::count());
        $this->assertTrue($result->map()->has($blog['video']));
    }

    public function test_morph_to_reuse_keeps_the_morph_id_and_type_and_resolves_aliases(): void
    {
        Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
        $blog = $this->seedBlog();
        $image = Image::query()->where('url', 'a.png')->firstOrFail();

        $this->assertSame('post', $image->imageable_type);

        $result = DeepReplicate::for($image)->reuse('imageable')->replicate();
        $copy = $result->model();

        $this->assertSame('post', $copy->imageable_type);
        $this->assertSame($image->imageable_id, $copy->imageable_id);
        $this->assertTrue($result->map()->isReused($blog['post']));
        $this->assertSame(2, Post::count());
    }

    public function test_morph_to_with_a_null_target_stays_null(): void
    {
        $activity = Activity::create(['description' => 'orphan']);

        $result = DeepReplicate::for($activity)->copy('subject')->replicate();

        $this->assertNull($result->model()->subject_type);
        $this->assertNull($result->model()->subject_id);
        $this->assertSame(1, $result->stats()->copiedModels());
    }

    public function test_morph_to_many_copy_and_reuse_write_the_morph_type_and_both_keys(): void
    {
        Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
        $blog = $this->seedBlog();
        $post = $blog['post'];

        $reused = DeepReplicate::for($post)->reuse('tags')->replicate()->model();
        $copied = DeepReplicate::for($post)->copy('tags')->replicate()->model();

        $reusedRows = DB::table('taggables')->where('taggable_id', $reused->id)->where('taggable_type', 'post')->orderBy('tag_id')->get();
        $this->assertEquals([$blog['red']->id, $blog['blue']->id], $reusedRows->pluck('tag_id')->all());
        $this->assertSame(['main', null], $reusedRows->pluck('context')->all());

        $copiedTags = DB::table('taggables')->where('taggable_id', $copied->id)->where('taggable_type', 'post')->pluck('tag_id')->all();
        $this->assertCount(2, $copiedTags);
        $this->assertEmpty(array_intersect([$blog['red']->id, $blog['blue']->id], $copiedTags));
        $this->assertSame(2, DB::table('taggables')->where('taggable_type', 'post')->where('taggable_id', $post->id)->count());
    }

    public function test_morphed_by_many_reuse_links_the_new_tag_to_the_original_owners(): void
    {
        $blog = $this->seedBlog();
        $red = $blog['red'];

        $result = DeepReplicate::for($red)->reuse('posts', 'videos')->replicate();
        $copy = $result->model();

        $rows = DB::table('taggables')->where('tag_id', $copy->id)->orderBy('taggable_type')->get();

        $this->assertSame([Post::class, Video::class], $rows->pluck('taggable_type')->all());
        $this->assertEquals([$blog['post']->id, $blog['video']->id], $rows->pluck('taggable_id')->all());
        $this->assertSame(['main', 'clip'], $rows->pluck('context')->all());
        $this->assertSame(2, $result->stats()->reusedModels());
    }

    public function test_morphed_by_many_copy_creates_the_owners(): void
    {
        $blog = $this->seedBlog();

        $result = DeepReplicate::for($blog['red'])->copy('posts')->replicate();

        $this->assertSame(3, Post::count());
        $this->assertSame(1, DB::table('taggables')->where('tag_id', $result->model()->id)->count());
    }

    public function test_through_and_custom_relations_are_rejected_but_may_be_skipped(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        foreach (['itemProducts' => 'HasManyThrough', 'firstItemProduct' => 'HasOneThrough', 'custom' => 'CustomRelation'] as $path => $type) {
            foreach (['copy', 'reuse'] as $strategy) {
                $exception = $this->assertThrown(UnsupportedRelationshipException::class, function () use ($order, $strategy, $path) {
                    DeepReplicate::for($order)->{$strategy}($path)->replicate();
                });

                $this->assertStringContainsString($type, $exception->getMessage());
                $this->assertStringContainsString("[{$path}]", $exception->getMessage());
            }
        }

        $this->assertSame($before, $this->counts());

        $result = DeepReplicate::for($order)->copy('items')->skip('itemProducts', 'firstItemProduct', 'custom')->replicate();

        $this->assertSame(3, $result->stats()->skippedRelationships());
        $this->assertSame(2, \Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Product::count());
    }

    public function test_custom_owner_and_local_keys_and_table_names(): void
    {
        $shelf = Shelf::create(['code' => 'S-1', 'label' => 'Fiction']);
        $book = Book::create(['shelf_code' => 'S-1', 'isbn' => '111', 'title' => 'Dune']);
        $author = Author::create(['pen_name' => 'frank']);
        DB::table('legacy_book_author')->insert(['book_ref' => '111', 'author_ref' => 'frank', 'role' => 'writer']);

        $result = DeepReplicate::for($shelf)
            ->copy('books')
            ->reuse('books.authors')
            ->generate('code', function () {
                return 'S-2';
            })
            ->configure('books', function (RelationRule $rule) {
                $rule->generate('isbn', function (Book $source) {
                    return $source->isbn.'-copy';
                });
            })
            ->replicate();

        $copy = $result->model();
        $newBook = $result->map()->get($book);

        $this->assertSame('S-2', $copy->code);
        $this->assertSame('S-2', $newBook->shelf_code);
        $this->assertSame('111-copy', $newBook->isbn);
        $this->assertSame(['frank'], $newBook->authors()->pluck('pen_name')->all());
        $this->assertSame('writer', DB::table('legacy_book_author')->where('book_ref', '111-copy')->value('role'));
        $this->assertSame('S-1', $book->fresh()->shelf_code);
        $this->assertTrue($result->map()->isReused($author));
    }

    public function test_belongs_to_with_custom_owner_key(): void
    {
        Shelf::create(['code' => 'S-1', 'label' => 'Fiction']);
        $book = Book::create(['shelf_code' => 'S-1', 'isbn' => '111', 'title' => 'Dune']);

        $copy = DeepReplicate::for($book)
            ->copy('shelf')
            ->generate('isbn', function () {
                return '222';
            })
            ->configure('shelf', function (RelationRule $rule) {
                $rule->with(['code' => 'S-9']);
            })
            ->replicate()
            ->model();

        $this->assertSame('S-9', $copy->shelf_code);
        $this->assertSame('Fiction', $copy->shelf->label);
        $this->assertSame('S-1', $book->fresh()->shelf_code);
    }
}
