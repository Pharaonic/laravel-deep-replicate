<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ProtectedRelationshipAttributeException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationLimitExceededException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\PlanningWarning;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\ReplicationContext;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemTag;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Post;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Tag;

test('every pivot column is kept even when undeclared', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();

    $copy = DeepReplicate::for($item)->reuse('plainTags')->replicate()->model();

    $source = DB::table('order_item_tag')->where('order_item_id', $item->id)->orderBy('tag_id')->get(['tag_id', 'primary', 'weight', 'options', 'note']);
    $copied = DB::table('order_item_tag')->where('order_item_id', $copy->id)->orderBy('tag_id')->get(['tag_id', 'primary', 'weight', 'options', 'note']);

    expect($copied->all())->toEqual($source->all());
});

test('surrogate pivot ids are new', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();
    $sourceIds = DB::table('order_item_tag')->pluck('id')->all();

    $copy = DeepReplicate::for($item)->reuse('plainTags')->replicate()->model();
    $newIds = DB::table('order_item_tag')->where('order_item_id', $copy->id)->pluck('id')->all();

    expect($newIds)->toHaveCount(2);
    expect(array_intersect($sourceIds, $newIds))->toBe([]);
});

test('pivot timestamps are new by default and preserved on request', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();

    $fresh = DeepReplicate::for($item)->reuse('plainTags')->replicate()->model();
    $kept = DeepReplicate::for($item)->reuse('plainTags')->preserveTimestamps()->replicate()->model();

    expect(DB::table('order_item_tag')->where('order_item_id', $fresh->id)->pluck('created_at')->all())->not->toContain('2020-01-01 00:00:00');
    expect(DB::table('order_item_tag')->where('order_item_id', $kept->id)->distinct()->pluck('created_at')->all())->toBe(['2020-01-01 00:00:00']);
    expect(DB::table('order_item_tag')->where('order_item_id', $kept->id)->distinct()->pluck('updated_at')->all())->toBe(['2020-01-02 00:00:00']);
});

test('transform pivot changes allowed columns only', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();
    $seen = [];

    $copy = DeepReplicate::for($item)
        ->reuse('plainTags')
        ->copyPivot('plainTags')
        ->transformPivot('plainTags', function (array $attributes, $pivot, ReplicationContext $context) use (&$seen) {
            $seen[] = [$context->path(), array_key_exists('order_item_id', $attributes), array_key_exists('id', $attributes)];
            $attributes['primary'] = false;
            $attributes['note'] = 'copied';

            return $attributes;
        })
        ->replicate()
        ->model();

    expect($seen)->toBe([['plainTags', false, false], ['plainTags', false, false]]);
    expect(DB::table('order_item_tag')->where('order_item_id', $copy->id)->distinct()->pluck('primary')->all())->toEqual([0]);
    expect(DB::table('order_item_tag')->where('order_item_id', $copy->id)->distinct()->pluck('note')->all())->toBe(['copied']);
    expect(DB::table('order_item_tag')->where('order_item_id', $item->id)->where('primary', true)->value('note'))->toBe('n1');

    foreach (['order_item_id', 'tag_id', 'id'] as $column) {
        $before = DB::table('order_item_tag')->count();

        $exception = $this->assertThrows(ProtectedRelationshipAttributeException::class, function () use ($item, $column) {
            DeepReplicate::for($item)->reuse('plainTags')->transformPivot('plainTags', function (array $attributes) use ($column) {
                $attributes[$column] = 1;

                return $attributes;
            })->replicate();
        });

        expect($exception->getMessage())->toContain("[{$column}]");
        expect(DB::table('order_item_tag')->count())->toBe($before);
    }
});

test('transform pivot cannot change the morph discriminator', function () {
    $post = $this->seedBlog()['post'];

    $this->assertThrows(ProtectedRelationshipAttributeException::class, function () use ($post) {
        DeepReplicate::for($post)->reuse('tags')->transformPivot('tags', function (array $attributes) {
            $attributes['taggable_type'] = 'other';

            return $attributes;
        })->replicate();
    });

    expect(Post::count())->toBe(2);
});

test('duplicate source pivot rows are kept distinct', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();
    $red = Tag::query()->where('name', 'red')->firstOrFail();
    DB::table('order_item_tag')->insert(['order_item_id' => $item->id, 'tag_id' => $red->id, 'weight' => 9]);

    $result = DeepReplicate::for($item)->reuse('plainTags')->replicate();

    expect($result->stats()->pivotRowsCreated())->toBe(3);
    expect($result->stats()->reusedModels())->toBe(2);
    expect(DB::table('order_item_tag')->where('order_item_id', $result->model()->id)->where('tag_id', $red->id)->orderBy('weight')->pluck('weight')->all())->toEqual([5, 9]);
});

test('a pivot constraint violation rolls everything back', function () {
    $order = $this->seedOrder();
    $red = Tag::query()->where('name', 'red')->firstOrFail();
    DB::table('order_tag_unique')->insert(['order_id' => $order->id, 'tag_id' => $red->id, 'code' => 'C-1']);
    $before = $this->counts();

    $this->assertThrows(QueryException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->reuse('uniqueTags')->replicate();
    });
    expect($this->counts())->toBe($before);

    $copy = DeepReplicate::for($order)->copy('items')->reuse('uniqueTags')->transformPivot('uniqueTags', function (array $attributes) {
        $attributes['code'] = 'C-2';

        return $attributes;
    })->replicate()->model();

    expect(DB::table('order_tag_unique')->where('order_id', $copy->id)->value('code'))->toBe('C-2');
});

test('custom pivot models are saved with their casts and events', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();

    $copy = DeepReplicate::for($item)->copy('tags')->replicate()->model();

    expect(ItemTag::$events)->toBe(['creating', 'created', 'creating', 'created']);
    $rows = DB::table('order_item_tag')->where('order_item_id', $copy->id)->orderBy('weight')->get();
    expect($rows->pluck('options')->all())->toBe([null, '{"a":1}']);
    expect($rows->pluck('note')->all())->toBe([null, 'n1']);

    $pivot = $copy->tags()->orderBy('weight', 'desc')->firstOrFail()->pivot;
    expect($pivot)->toBeInstanceOf(ItemTag::class);
    expect($pivot->options)->toBe(['a' => 1]);
    expect($pivot->primary)->toBeTrue();
});

test('max pivot rows is enforced before any write', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $writes = $this->captureWrites(function () use ($order) {
        $this->assertThrows(ReplicationLimitExceededException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->reuse('items.plainTags')->maxPivotRows(2)->replicate();
        });
    });

    expect($writes)->toBe([]);
    expect($this->counts())->toBe($before);
    expect(DeepReplicate::for($order)->copy('items')->reuse('items.plainTags')->maxPivotRows(3)->replicate()->stats()->pivotRowsCreated())->toBe(3);
});

test('pivot inserts are batched by chunk', function () {
    $order = $this->seedOrder();
    $inserts = function (int $chunk) use ($order): int {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (preg_match('/^insert into ["`]?order_item_tag["`]?/i', $query->sql)) {
                $count++;
            }
        });
        DeepReplicate::for($order)->copy('items')->reuse('items.plainTags')->chunk($chunk)->replicate();

        return $count;
    };

    expect($inserts(500))->toBe(1);
    expect($inserts(1))->toBe(3);
});

test('loaded pivots copy what was loaded and warn about the rest', function () {
    $order = $this->seedOrder();
    $item = OrderItem::query()->orderBy('id')->firstOrFail()->load('tags');

    $plan = DeepReplicate::for($item)->reuse('tags')->usingLoaded('tags')->dryRun();
    $warning = $plan->warnings()[0];

    expect($warning->code())->toBe(PlanningWarning::UNLOADED_PIVOT_COLUMNS);
    expect($warning->message())->toContain('note');

    $copy = DeepReplicate::for($item)->reuse('tags')->usingLoaded('tags')->replicate()->model();

    expect(DB::table('order_item_tag')->where('order_item_id', $copy->id)->distinct()->pluck('note')->all())->toBe([null]);
    expect(DB::table('order_item_tag')->where('order_item_id', $copy->id)->orderBy('weight')->pluck('weight')->all())->toEqual([1, 5]);
});

test('pivot relation constraints select the rows', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();

    $result = DeepReplicate::for($item)->reuse('primaryTags')->replicate();

    expect($result->stats()->pivotRowsCreated())->toBe(1);
    expect($result->model()->plainTags()->pluck('name')->all())->toBe(['red']);
});

test('copied targets shared by two parents get one copy and two pivots', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)->copy('items', 'items.plainTags')->replicate();
    $red = Tag::query()->where('name', 'red')->orderBy('id')->firstOrFail();
    $copiedRed = $result->map()->get($red);

    expect($copiedRed)->not->toBeNull();
    expect($result->stats()->copiedModels())->toBe(1 + 2 + 2);
    expect($result->stats()->pivotRowsCreated())->toBe(3);
    expect(DB::table('order_item_tag')->where('tag_id', $copiedRed->id)->count())->toBe(2);
    expect(Order::count())->toBe(2);
});

test('path rule without events silences pivot models of that path', function () {
    $order = $this->seedOrder();
    $item = $order->items()->orderBy('id')->firstOrFail();

    DeepReplicate::for($order)->copy('items', 'items.tags')->configure('items.tags', function (RelationRule $rule) {
        $rule->withoutEvents();
    })->filter('items', function ($query) use ($item) {
        $query->whereKey($item->id);
    })->replicate();

    expect(ItemTag::$events)->toBe([]);
});
