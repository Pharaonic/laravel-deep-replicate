<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Pharaonic\Laravel\DeepReplicate\Exceptions\ConflictingRelationshipRuleException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\EmptyRelationshipSelectionException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\IncompatibleRelationOptionsException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\UnsupportedReuseException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class ContractExamplesTest extends TestCase
{
    public function test_fluent_facade(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)
            ->copy('items', 'items.options')
            ->reuse('items.product', 'customer')
            ->skip('payments')
            ->replicate();

        $this->assertTrue($result->model()->exists);
        $this->assertNotSame($order->getKey(), $result->model()->getKey());

        /** @var Order $copy */
        $copy = $result->model();
        $this->assertSame(2, $copy->items()->count());
        $this->assertSame(3, $copy->items()->withCount('options')->get()->sum('options_count'));
        $this->assertSame(0, $copy->payments()->count());
        $this->assertSame($order->customer_id, $copy->customer_id);
        $this->assertSame(
            $order->items()->orderBy('id')->pluck('product_id')->all(),
            $copy->items()->orderBy('id')->pluck('product_id')->all()
        );
    }

    public function test_trait_with_array(): void
    {
        $order = $this->seedOrder();

        $result = $order->deepReplicate([
            'items' => 'copy',
            'items.options' => 'copy',
            'items.product' => 'reuse',
            'customer' => 'reuse',
            'payments' => 'skip',
        ])->replicate();

        $this->assertSame(6, $result->stats()->copiedModels());
        $this->assertSame(3, $result->stats()->reusedModels());
    }

    public function test_trait_with_fluent_configuration(): void
    {
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
        $this->assertSame('draft', $copy->status);
        $this->assertSame([null, null], $copy->items()->pluck('external_id')->all());
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_intentional_failures(): void
    {
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

        $this->assertSame($before, $this->counts());
    }

    public function test_default_foreign_key_retention(): void
    {
        $post = $this->seedBlog()['post'];

        $new = DeepReplicate::for($post)->copy('comments')->replicate()->model();

        $this->assertSame($post->author_id, $new->author_id);
    }

    public function test_new_owner_with_the_same_related_tags(): void
    {
        $blog = $this->seedBlog();
        $post = $blog['post'];
        $tags = $this->rows('tags');
        $pivots = $this->rows('taggables');

        $result = DeepReplicate::for($post)->reuse('tags')->replicate();

        $this->assertEquals(
            $post->tags()->orderBy('tags.id')->pluck('tags.id')->all(),
            $result->model()->tags()->orderBy('tags.id')->pluck('tags.id')->all()
        );
        $this->assertSame($tags, $this->rows('tags'));
        $this->assertSame($pivots, array_slice($this->rows('taggables'), 0, count($pivots)));
        $this->assertSame(2, $post->tags()->count());
    }

    public function test_fresh_and_loaded_distinction(): void
    {
        $post = $this->seedBlog()['post'];
        $post->load(['comments' => function ($q) {
            $q->where('approved', true);
        }]);

        $all = DeepReplicate::for($post)->copy('comments')->replicate();
        $subset = DeepReplicate::for($post)->copy('comments')->usingLoaded('comments')->replicate();

        $this->assertSame(2, $all->model()->comments()->count());
        $this->assertSame(1, $subset->model()->comments()->count());
    }

    public function test_inspection(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $plan = DeepReplicate::for($order)
            ->copy('items', 'items.options')
            ->reuse('items.product')
            ->dryRun();

        $this->assertSame($before, $this->counts());
        $this->assertSame(6, $plan->stats()->copiedModels());

        $result = DeepReplicate::for($order)
            ->copy('items', 'items.options')
            ->reuse('items.product')
            ->replicate();

        $replica = $result->model();
        $mapping = $result->map();
        $stats = $result->stats();

        $this->assertTrue($replica->exists);
        $this->assertCount(6, $mapping->all());
        $this->assertSame($plan->stats()->copiedModels(), $stats->copiedModels());
        $this->assertSame($plan->stats()->reusedModels(), $stats->reusedModels());
    }
}
