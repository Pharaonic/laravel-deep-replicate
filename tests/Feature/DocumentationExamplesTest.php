<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Illuminate\Support\Str;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\ReplicationResult;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Customer;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Product;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

/**
 * The README and docs/ examples, run against the test fixtures.
 */
class DocumentationExamplesTest extends TestCase
{
    public function test_readme_and_quick_look_example(): void
    {
        $order = $this->seedOrder();
        $oldItem = $order->items()->orderBy('id')->firstOrFail();

        $result = DeepReplicate::for($order)
            ->copy('items', 'items.options')
            ->reuse('items.product', 'customer')
            ->skip('payments')
            ->with(['status' => 'draft'])
            ->replicate();

        $this->assertSame('draft', $result->model()->status);
        $this->assertSame($oldItem->title, $result->map()->get($oldItem)->title);
        $this->assertSame(1 + 2 + 3, $result->stats()->copiedModels());
    }

    public function test_order_template_recipe(): void
    {
        $order = $this->seedOrder('order-a-100');

        $copy = $order->deepReplicate('items', 'items.options')
            ->reuse('items.product', 'customer')
            ->skip('payments')
            ->with(['status' => 'draft'])
            ->generate('slug', function () {
                return 'order-'.Str::lower(Str::random(10));
            })
            ->configure('items', function (RelationRule $rule) {
                $rule->exclude(['external_id'])->with(['state' => 'pending']);
            })
            ->replicate()
            ->model();

        $this->assertStringStartsWith('order-', $copy->slug);
        $this->assertNotSame('order-a-100', $copy->slug);
        $this->assertSame(['pending'], $copy->items()->distinct()->pluck('state')->all());
        $this->assertSame(0, $copy->payments()->count());
    }

    public function test_shared_taxonomy_recipe(): void
    {
        $order = $this->seedOrder();

        $result = DeepReplicate::for($order)
            ->copy('items', 'items.options')
            ->reuse('items.plainTags')
            ->configure('items', function (RelationRule $rule) {
                $rule->generate('sku', function () {
                    return null;
                });
            })
            ->replicate();

        $this->assertSame(3, $result->stats()->pivotRowsCreated());
        $this->assertSame(2, $result->stats()->reusedModels());
    }

    public function test_translation_recipe_with_after_commit(): void
    {
        $post = $this->seedBlog()['post'];
        $dispatched = [];

        $copy = DeepReplicate::for($post)
            ->copy('comments', 'images')
            ->reuse('tags', 'author')
            ->with(['title' => 'Premier'])
            ->afterCommit(function (ReplicationResult $result) use (&$dispatched) {
                $dispatched[] = $result->model()->getKey();
            })
            ->replicate()
            ->model();

        $this->assertSame([$copy->getKey()], $dispatched);
        $this->assertSame('Premier', $copy->title);
        $this->assertSame($post->author_id, $copy->author_id);
        $this->assertSame(2, $copy->tags()->count());
    }

    public function test_check_before_running_recipe(): void
    {
        $order = $this->seedOrder();
        $builder = function () use ($order) {
            return DeepReplicate::for($order)->copy('items', 'items.options')->reuse('items.product');
        };

        $plan = $builder()->dryRun();

        $this->assertSame(8, $plan->estimatedModels());
        $this->assertSame(6, $builder()->maxModels(8)->replicate()->stats()->copiedModels());
    }

    public function test_tenant_recipe_sets_the_unmentioned_key_explicitly(): void
    {
        $order = $this->seedOrder();
        $target = Customer::create(['name' => 'Other tenant']);

        $same = DeepReplicate::for($order)->copy('items')->replicate()->model();
        $moved = DeepReplicate::for($order)->copy('items')->with(['customer_id' => $target->id])->replicate()->model();

        $this->assertSame($order->customer_id, $same->customer_id);
        $this->assertEquals($target->id, $moved->customer_id);
        $this->assertSame(2, Product::count());
        $this->assertSame(3, Order::count());
    }

    public function test_the_trait_and_facade_equivalence_table(): void
    {
        $order = $this->seedOrder();
        $rows = [
            [$order->deepReplicate('items'), DeepReplicate::for($order)->copy('items')],
            [$order->deepReplicate('items', 'customer'), DeepReplicate::for($order)->copy('items', 'customer')],
            [$order->deepReplicate(['customer' => 'reuse']), DeepReplicate::for($order)->reuse('customer')],
            [$order->deepReplicate(['items', 'customer' => 'reuse']), DeepReplicate::for($order, ['items', 'customer' => 'reuse'])],
        ];

        foreach ($rows as $index => [$trait, $facade]) {
            $viaTrait = $trait->dryRun();
            $viaFacade = $facade->dryRun();

            $this->assertSame($viaFacade->relationships(), $viaTrait->relationships(), "row #{$index}");
            $this->assertSame($viaFacade->estimatedModels(), $viaTrait->estimatedModels(), "row #{$index}");
        }
    }
}
