<?php

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

test('has one copy creates a new child for the new parent', function () {
    $order = $this->seedOrder();
    $invoice = Invoice::create(['order_id' => $order->id, 'code' => 'INV-1']);

    $copy = DeepReplicate::for($order)->copy('invoice')->replicate()->model();

    expect(Invoice::count())->toBe(2);
    expect($copy->invoice->code)->toBe('INV-1');
    expect($copy->invoice->id)->not->toEqual($invoice->id);
    expect($invoice->fresh()->order_id)->toEqual($order->id);
});

test('has one skip leaves the child alone', function () {
    $order = $this->seedOrder();
    Invoice::create(['order_id' => $order->id, 'code' => 'INV-1']);

    $copy = DeepReplicate::for($order)->copy('items')->skip('invoice')->replicate()->model();

    expect(Invoice::count())->toBe(1);
    expect($copy->invoice)->toBeNull();
});

test('reuse of child relations is rejected before any write', function () {
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
        $exception = $this->assertThrows(UnsupportedReuseException::class, function () use ($model, $path) {
            DeepReplicate::for($model)->reuse($path)->replicate();
        });

        expect($exception->getMessage())->toContain("[{$path}]");
        expect($exception->getMessage())->toContain($type);
        expect($exception->getMessage())->toContain(get_class($model));
    }

    expect($this->counts())->toBe($before);
});

test('has many copy copies every child and filters select a subset', function () {
    $order = $this->seedOrder();

    $all = DeepReplicate::for($order)->copy('items')->replicate()->model();
    $active = DeepReplicate::for($order)->copy('items')->filter('items', function ($query) {
        $query->where('active', true);
    })->replicate()->model();
    $scoped = DeepReplicate::for($order)->copy('activeItems')->replicate()->model();

    expect($all->items()->orderBy('id')->pluck('title')->all())->toBe(['Lamp', 'Desk']);
    expect($active->items()->pluck('title')->all())->toBe(['Lamp']);
    expect($scoped->items()->pluck('title')->all())->toBe(['Lamp']);
    expect($order->items()->count())->toBe(2);
});

test('belongs to copy creates the parent and rewrites the foreign key', function () {
    $order = $this->seedOrder();

    $copy = DeepReplicate::for($order)->copy('customer')->replicate()->model();

    expect(Customer::count())->toBe(2);
    expect($copy->customer_id)->not->toEqual($order->customer_id);
    expect($copy->customer->name)->toBe('Alice');
    expect($order->fresh()->customer_id)->toEqual($order->customer_id);
});

test('belongs to reuse keeps the foreign key', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)->reuse('customer')->replicate();

    expect(Customer::count())->toBe(1);
    expect($result->model()->customer_id)->toBe($order->customer_id);
    expect($result->stats()->reusedModels())->toBe(1);
});

test('belongs to with a null target stays null', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)->copy('assignedCustomer')->replicate();

    expect($result->model()->assigned_customer_id)->toBeNull();
    expect($result->stats()->copiedModels())->toBe(1);
    expect(Customer::count())->toBe(1);
});

test('belongs to skip and unmentioned keep the foreign key', function () {
    $blog = $this->seedBlog();
    $post = $blog['post'];

    $skipped = DeepReplicate::for($post)->copy('comments')->skip('author')->replicate()->model();
    $unmentioned = DeepReplicate::for($post)->copy('comments')->replicate()->model();

    expect($skipped->author_id)->toBe($post->author_id);
    expect($unmentioned->author_id)->toBe($post->author_id);
});

test('belongs to many copy creates targets and pivot rows', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->first();
    $tags = Tag::count();

    $result = DeepReplicate::for($item)->copy('plainTags')->replicate();
    $copy = $result->model();

    expect(Tag::count())->toBe($tags + 2);
    expect($result->stats()->pivotRowsCreated())->toBe(2);
    expect($copy->plainTags()->orderBy('name')->pluck('name')->all())->toBe(['blue', 'red']);
    expect(array_intersect($item->plainTags()->pluck('tags.id')->all(), $copy->plainTags()->pluck('tags.id')->all()))->toBeEmpty();
});

test('belongs to many reuse links the same targets with new pivot rows', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->first();
    $pivots = $this->rows('order_item_tag');

    $result = DeepReplicate::for($item)->reuse('plainTags')->replicate();
    $copy = $result->model();

    expect(Tag::count())->toBe(2);
    expect($result->stats()->reusedModels())->toBe(2);
    expect($copy->plainTags()->orderBy('tags.id')->pluck('tags.id')->all())->toEqual($item->plainTags()->orderBy('tags.id')->pluck('tags.id')->all());
    expect(array_slice($this->rows('order_item_tag'), 0, count($pivots)))->toBe($pivots);
    expect(DB::table('order_item_tag')->count())->toBe(count($pivots) + 2);
});

test('belongs to many skip creates no pivot rows', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->first();

    DeepReplicate::for($item)->copy('options')->skip('plainTags')->replicate();

    expect(DB::table('order_item_tag')->count())->toBe(3);
});

test('morph one and morph many copies get the new morph id and type', function () {
    $blog = $this->seedBlog();
    $post = $blog['post'];

    $copy = DeepReplicate::for($post)->copy('images')->replicate()->model();
    $featured = DeepReplicate::for($post)->copy('featuredImage')->replicate()->model();

    expect($copy->images()->orderBy('id')->pluck('url')->all())->toBe(['a.png', 'b.png']);
    expect($copy->images()->distinct()->pluck('imageable_type')->all())->toBe([Post::class]);
    expect($featured->featuredImage->url)->toBe('a.png');
    expect($featured->featuredImage->imageable_id)->toEqual($featured->id);
    expect($post->images()->count())->toBe(2);
    expect(Image::count())->toBe(6);
});

test('morph many uses the morph map alias', function () {
    Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
    $blog = $this->seedBlog();

    $copy = DeepReplicate::for($blog['post'])->copy('images')->replicate()->model();

    expect($copy->images()->distinct()->pluck('imageable_type')->all())->toBe(['post']);
});

test('morph to copy creates the concrete target and updates the morph id', function () {
    $blog = $this->seedBlog();
    $image = Image::query()->where('url', 'v.png')->firstOrFail();

    $result = DeepReplicate::for($image)->copy('imageable')->replicate();
    $copy = $result->model();

    expect($copy->imageable)->toBeInstanceOf(Video::class);
    expect($copy->imageable_id)->not->toEqual($blog['video']->id);
    expect($copy->imageable_type)->toBe($image->imageable_type);
    expect(Video::count())->toBe(2);
    expect($result->map()->has($blog['video']))->toBeTrue();
});

test('morph to reuse keeps the morph id and type and resolves aliases', function () {
    Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
    $blog = $this->seedBlog();
    $image = Image::query()->where('url', 'a.png')->firstOrFail();

    expect($image->imageable_type)->toBe('post');

    $result = DeepReplicate::for($image)->reuse('imageable')->replicate();
    $copy = $result->model();

    expect($copy->imageable_type)->toBe('post');
    expect($copy->imageable_id)->toBe($image->imageable_id);
    expect($result->map()->isReused($blog['post']))->toBeTrue();
    expect(Post::count())->toBe(2);
});

test('morph to with a null target stays null', function () {
    $activity = Activity::create(['description' => 'orphan']);

    $result = DeepReplicate::for($activity)->copy('subject')->replicate();

    expect($result->model()->subject_type)->toBeNull();
    expect($result->model()->subject_id)->toBeNull();
    expect($result->stats()->copiedModels())->toBe(1);
});

test('morph to many copy and reuse write the morph type and both keys', function () {
    Relation::morphMap(['post' => Post::class, 'video' => Video::class]);
    $blog = $this->seedBlog();
    $post = $blog['post'];

    $reused = DeepReplicate::for($post)->reuse('tags')->replicate()->model();
    $copied = DeepReplicate::for($post)->copy('tags')->replicate()->model();

    $reusedRows = DB::table('taggables')->where('taggable_id', $reused->id)->where('taggable_type', 'post')->orderBy('tag_id')->get();
    expect($reusedRows->pluck('tag_id')->all())->toEqual([$blog['red']->id, $blog['blue']->id]);
    expect($reusedRows->pluck('context')->all())->toBe(['main', null]);

    $copiedTags = DB::table('taggables')->where('taggable_id', $copied->id)->where('taggable_type', 'post')->pluck('tag_id')->all();
    expect($copiedTags)->toHaveCount(2);
    expect(array_intersect([$blog['red']->id, $blog['blue']->id], $copiedTags))->toBeEmpty();
    expect(DB::table('taggables')->where('taggable_type', 'post')->where('taggable_id', $post->id)->count())->toBe(2);
});

test('morphed by many reuse links the new tag to the original owners', function () {
    $blog = $this->seedBlog();
    $red = $blog['red'];

    $result = DeepReplicate::for($red)->reuse('posts', 'videos')->replicate();
    $copy = $result->model();

    $rows = DB::table('taggables')->where('tag_id', $copy->id)->orderBy('taggable_type')->get();

    expect($rows->pluck('taggable_type')->all())->toBe([Post::class, Video::class]);
    expect($rows->pluck('taggable_id')->all())->toEqual([$blog['post']->id, $blog['video']->id]);
    expect($rows->pluck('context')->all())->toBe(['main', 'clip']);
    expect($result->stats()->reusedModels())->toBe(2);
});

test('morphed by many copy creates the owners', function () {
    $blog = $this->seedBlog();

    $result = DeepReplicate::for($blog['red'])->copy('posts')->replicate();

    expect(Post::count())->toBe(3);
    expect(DB::table('taggables')->where('tag_id', $result->model()->id)->count())->toBe(1);
});

test('through and custom relations are rejected but may be skipped', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    foreach (['itemProducts' => 'HasManyThrough', 'firstItemProduct' => 'HasOneThrough', 'custom' => 'CustomRelation'] as $path => $type) {
        foreach (['copy', 'reuse'] as $strategy) {
            $exception = $this->assertThrows(UnsupportedRelationshipException::class, function () use ($order, $strategy, $path) {
                DeepReplicate::for($order)->{$strategy}($path)->replicate();
            });

            expect($exception->getMessage())->toContain($type);
            expect($exception->getMessage())->toContain("[{$path}]");
        }
    }

    expect($this->counts())->toBe($before);

    $result = DeepReplicate::for($order)->copy('items')->skip('itemProducts', 'firstItemProduct', 'custom')->replicate();

    expect($result->stats()->skippedRelationships())->toBe(3);
    expect(\Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Product::count())->toBe(2);
});

test('custom owner and local keys and table names', function () {
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

    expect($copy->code)->toBe('S-2');
    expect($newBook->shelf_code)->toBe('S-2');
    expect($newBook->isbn)->toBe('111-copy');
    expect($newBook->authors()->pluck('pen_name')->all())->toBe(['frank']);
    expect(DB::table('legacy_book_author')->where('book_ref', '111-copy')->value('role'))->toBe('writer');
    expect($book->fresh()->shelf_code)->toBe('S-1');
    expect($result->map()->isReused($author))->toBeTrue();
});

test('belongs to with custom owner key', function () {
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

    expect($copy->shelf_code)->toBe('S-9');
    expect($copy->shelf->label)->toBe('Fiction');
    expect($book->fresh()->shelf_code)->toBe('S-1');
});
