<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

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
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class PivotTest extends TestCase
{
    public function test_every_pivot_column_is_kept_even_when_undeclared(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->firstOrFail();

        $copy = DeepReplicate::for($item)->reuse('plainTags')->replicate()->model();

        $source = DB::table('order_item_tag')->where('order_item_id', $item->id)->orderBy('tag_id')->get(['tag_id', 'primary', 'weight', 'options', 'note']);
        $copied = DB::table('order_item_tag')->where('order_item_id', $copy->id)->orderBy('tag_id')->get(['tag_id', 'primary', 'weight', 'options', 'note']);

        $this->assertEquals($source->all(), $copied->all());
    }

    public function test_surrogate_pivot_ids_are_new(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->firstOrFail();
        $sourceIds = DB::table('order_item_tag')->pluck('id')->all();

        $copy = DeepReplicate::for($item)->reuse('plainTags')->replicate()->model();
        $newIds = DB::table('order_item_tag')->where('order_item_id', $copy->id)->pluck('id')->all();

        $this->assertCount(2, $newIds);
        $this->assertSame([], array_intersect($sourceIds, $newIds));
    }

    public function test_pivot_timestamps_are_new_by_default_and_preserved_on_request(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->firstOrFail();

        $fresh = DeepReplicate::for($item)->reuse('plainTags')->replicate()->model();
        $kept = DeepReplicate::for($item)->reuse('plainTags')->preserveTimestamps()->replicate()->model();

        $this->assertNotContains('2020-01-01 00:00:00', DB::table('order_item_tag')->where('order_item_id', $fresh->id)->pluck('created_at')->all());
        $this->assertSame(['2020-01-01 00:00:00'], DB::table('order_item_tag')->where('order_item_id', $kept->id)->distinct()->pluck('created_at')->all());
        $this->assertSame(['2020-01-02 00:00:00'], DB::table('order_item_tag')->where('order_item_id', $kept->id)->distinct()->pluck('updated_at')->all());
    }

    public function test_transform_pivot_changes_allowed_columns_only(): void
    {
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

        $this->assertSame([['plainTags', false, false], ['plainTags', false, false]], $seen);
        $this->assertEquals([0], DB::table('order_item_tag')->where('order_item_id', $copy->id)->distinct()->pluck('primary')->all());
        $this->assertSame(['copied'], DB::table('order_item_tag')->where('order_item_id', $copy->id)->distinct()->pluck('note')->all());
        $this->assertSame('n1', DB::table('order_item_tag')->where('order_item_id', $item->id)->where('primary', true)->value('note'));

        foreach (['order_item_id', 'tag_id', 'id'] as $column) {
            $before = DB::table('order_item_tag')->count();

            $exception = $this->assertThrows(ProtectedRelationshipAttributeException::class, function () use ($item, $column) {
                DeepReplicate::for($item)->reuse('plainTags')->transformPivot('plainTags', function (array $attributes) use ($column) {
                    $attributes[$column] = 1;

                    return $attributes;
                })->replicate();
            });

            $this->assertStringContainsString("[{$column}]", $exception->getMessage());
            $this->assertSame($before, DB::table('order_item_tag')->count());
        }
    }

    public function test_transform_pivot_cannot_change_the_morph_discriminator(): void
    {
        $post = $this->seedBlog()['post'];

        $this->assertThrows(ProtectedRelationshipAttributeException::class, function () use ($post) {
            DeepReplicate::for($post)->reuse('tags')->transformPivot('tags', function (array $attributes) {
                $attributes['taggable_type'] = 'other';

                return $attributes;
            })->replicate();
        });

        $this->assertSame(2, Post::count());
    }

    public function test_duplicate_source_pivot_rows_are_kept_distinct(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->firstOrFail();
        $red = Tag::query()->where('name', 'red')->firstOrFail();
        DB::table('order_item_tag')->insert(['order_item_id' => $item->id, 'tag_id' => $red->id, 'weight' => 9]);

        $result = DeepReplicate::for($item)->reuse('plainTags')->replicate();

        $this->assertSame(3, $result->stats()->pivotRowsCreated());
        $this->assertSame(2, $result->stats()->reusedModels());
        $this->assertEquals([5, 9], DB::table('order_item_tag')->where('order_item_id', $result->model()->id)->where('tag_id', $red->id)->orderBy('weight')->pluck('weight')->all());
    }

    public function test_a_pivot_constraint_violation_rolls_everything_back(): void
    {
        $order = $this->seedOrder();
        $red = Tag::query()->where('name', 'red')->firstOrFail();
        DB::table('order_tag_unique')->insert(['order_id' => $order->id, 'tag_id' => $red->id, 'code' => 'C-1']);
        $before = $this->counts();

        $this->assertThrows(QueryException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->reuse('uniqueTags')->replicate();
        });
        $this->assertSame($before, $this->counts());

        $copy = DeepReplicate::for($order)->copy('items')->reuse('uniqueTags')->transformPivot('uniqueTags', function (array $attributes) {
            $attributes['code'] = 'C-2';

            return $attributes;
        })->replicate()->model();

        $this->assertSame('C-2', DB::table('order_tag_unique')->where('order_id', $copy->id)->value('code'));
    }

    public function test_custom_pivot_models_are_saved_with_their_casts_and_events(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->firstOrFail();

        $copy = DeepReplicate::for($item)->copy('tags')->replicate()->model();

        $this->assertSame(['creating', 'created', 'creating', 'created'], ItemTag::$events);
        $rows = DB::table('order_item_tag')->where('order_item_id', $copy->id)->orderBy('weight')->get();
        $this->assertSame([null, '{"a":1}'], $rows->pluck('options')->all());
        $this->assertSame([null, 'n1'], $rows->pluck('note')->all());

        $pivot = $copy->tags()->orderBy('weight', 'desc')->firstOrFail()->pivot;
        $this->assertInstanceOf(ItemTag::class, $pivot);
        $this->assertSame(['a' => 1], $pivot->options);
        $this->assertTrue($pivot->primary);
    }

    public function test_max_pivot_rows_is_enforced_before_any_write(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $writes = $this->captureWrites(function () use ($order) {
            $this->assertThrows(ReplicationLimitExceededException::class, function () use ($order) {
                DeepReplicate::for($order)->copy('items')->reuse('items.plainTags')->maxPivotRows(2)->replicate();
            });
        });

        $this->assertSame([], $writes);
        $this->assertSame($before, $this->counts());
        $this->assertSame(3, DeepReplicate::for($order)->copy('items')->reuse('items.plainTags')->maxPivotRows(3)->replicate()->stats()->pivotRowsCreated());
    }

    public function test_pivot_inserts_are_batched_by_chunk(): void
    {
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

        $this->assertSame(1, $inserts(500));
        $this->assertSame(3, $inserts(1));
    }

    public function test_loaded_pivots_copy_what_was_loaded_and_warn_about_the_rest(): void
    {
        $order = $this->seedOrder();
        $item = OrderItem::query()->orderBy('id')->firstOrFail()->load('tags');

        $plan = DeepReplicate::for($item)->reuse('tags')->usingLoaded('tags')->dryRun();
        $warning = $plan->warnings()[0];

        $this->assertSame(PlanningWarning::UNLOADED_PIVOT_COLUMNS, $warning->code());
        $this->assertStringContainsString('note', $warning->message());

        $copy = DeepReplicate::for($item)->reuse('tags')->usingLoaded('tags')->replicate()->model();

        $this->assertSame([null], DB::table('order_item_tag')->where('order_item_id', $copy->id)->distinct()->pluck('note')->all());
        $this->assertEquals([1, 5], DB::table('order_item_tag')->where('order_item_id', $copy->id)->orderBy('weight')->pluck('weight')->all());
    }

    public function test_pivot_relation_constraints_select_the_rows(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->firstOrFail();

        $result = DeepReplicate::for($item)->reuse('primaryTags')->replicate();

        $this->assertSame(1, $result->stats()->pivotRowsCreated());
        $this->assertSame(['red'], $result->model()->plainTags()->pluck('name')->all());
    }

    public function test_copied_targets_shared_by_two_parents_get_one_copy_and_two_pivots(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)->copy('items', 'items.plainTags')->replicate();
        $red = Tag::query()->where('name', 'red')->orderBy('id')->firstOrFail();
        $copiedRed = $result->map()->get($red);

        $this->assertNotNull($copiedRed);
        $this->assertSame(1 + 2 + 2, $result->stats()->copiedModels());
        $this->assertSame(3, $result->stats()->pivotRowsCreated());
        $this->assertSame(2, DB::table('order_item_tag')->where('tag_id', $copiedRed->id)->count());
        $this->assertSame(2, Order::count());
    }

    public function test_path_rule_without_events_silences_pivot_models_of_that_path(): void
    {
        $order = $this->seedOrder();
        $item = $order->items()->orderBy('id')->firstOrFail();

        DeepReplicate::for($order)->copy('items', 'items.tags')->configure('items.tags', function (RelationRule $rule) {
            $rule->withoutEvents();
        })->filter('items', function ($query) use ($item) {
            $query->whereKey($item->id);
        })->replicate();

        $this->assertSame([], ItemTag::$events);
    }
}
