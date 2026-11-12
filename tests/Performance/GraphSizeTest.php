<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Customer;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Tag;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

/**
 * Query and memory budgets for growing graphs: an order with N items, eight
 * options per item and one reused tag per item (one pivot row per item).
 *
 * Set DEEP_REPLICATE_BENCHMARK=10,100,1000,10000 to choose the sizes; the
 * measured numbers are written to STDERR.
 */
class GraphSizeTest extends TestCase
{
    /**
     * @return array<string, array{0: int}>
     */
    public static function sizes(): array
    {
        $sizes = getenv('DEEP_REPLICATE_BENCHMARK') ?: '10,100,1000';
        $cases = [];

        foreach (array_filter(array_map('intval', explode(',', $sizes))) as $size) {
            $cases["{$size} models"] = [$size];
        }

        return $cases;
    }

    /**
     * @dataProvider sizes
     */
    public function test_queries_stay_batched_as_the_graph_grows(int $size): void
    {
        $order = $this->seedGraph($size);
        $items = intdiv($size - 1, 9);
        $models = 1 + $items * 9;
        $selects = 0;
        $inserts = 0;

        DB::listen(function ($query) use (&$selects, &$inserts) {
            if (stripos(ltrim($query->sql), 'select') === 0) {
                $selects++;
            } elseif (stripos(ltrim($query->sql), 'insert') === 0) {
                $inserts++;
            }
        });

        $memory = memory_get_usage();
        $started = microtime(true);

        $result = DeepReplicate::for($order)
            ->copy('items', 'items.options')
            ->reuse('items.plainTags')
            ->maxModels(max(10000, $models + 1))
            ->replicate();

        $elapsed = (microtime(true) - $started) * 1000;
        $peak = (memory_get_peak_usage() - $memory) / 1048576;
        $chunks = (int) max(1, ceil($items / 500));

        $this->assertSame($models, $result->stats()->copiedModels());
        $this->assertSame($items, $result->stats()->pivotRowsCreated());

        // Items once, options and pivots once per chunk of 500 items, plus
        // the pivot table's column listing: never one query per parent.
        $this->assertLessThanOrEqual(1 + 2 * $chunks + 2, $selects);
        // One insert per copied model and one per 500 pivot rows.
        $this->assertSame($models + (int) ceil($items / 500), $inserts);

        fwrite(STDERR, sprintf(
            "\n[%s] %6d models: %5d selects, %6d inserts, %8.1f ms, %6.1f MB peak",
            DB::connection()->getDriverName(),
            $models,
            $selects,
            $inserts,
            $elapsed,
            max(0, $peak)
        ));
    }

    private function seedGraph(int $size): Order
    {
        $customer = Customer::create(['name' => 'Bulk']);
        $tag = Tag::create(['name' => 'bulk']);
        $order = new Order(['number' => 'bulk']);
        $order->customer_id = $customer->id;
        $order->save();

        $items = intdiv($size - 1, 9);
        $now = now()->toDateTimeString();

        for ($offset = 0; $offset < $items; $offset += 500) {
            $rows = [];

            for ($i = $offset; $i < min($items, $offset + 500); $i++) {
                $rows[] = ['order_id' => $order->id, 'title' => "Item {$i}", 'created_at' => $now, 'updated_at' => $now];
            }

            DB::table('order_items')->insert($rows);
        }

        $ids = DB::table('order_items')->where('order_id', $order->id)->pluck('id')->all();

        foreach (array_chunk($ids, 100) as $chunk) {
            $options = [];
            $pivots = [];

            foreach ($chunk as $id) {
                for ($o = 0; $o < 8; $o++) {
                    $options[] = ['order_item_id' => $id, 'name' => "Option {$o}", 'created_at' => $now, 'updated_at' => $now];
                }

                $pivots[] = ['order_item_id' => $id, 'tag_id' => $tag->id, 'weight' => 1];
            }

            DB::table('item_options')->insert($options);
            DB::table('order_item_tag')->insert($pivots);
        }

        return $order->fresh();
    }
}
