<?php

namespace Pharaonic\Laravel\DeepReplicate\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pharaonic\Laravel\DeepReplicate\Exceptions\CrossConnectionReplicationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\MissingPrimaryKeyGeneratorException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ProtectedRelationshipAttributeException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\ReplicationContext;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Book;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Document;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Page;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Shelf;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Ticket;
use Pharaonic\Laravel\DeepReplicate\Tests\TestCase;

class AttributesTest extends TestCase
{
    public function test_string_keys_need_an_explicit_generator(): void
    {
        $document = Document::create(['uuid' => 'doc-1', 'title' => 'Spec']);
        Page::create(['uuid' => 'page-1', 'document_uuid' => 'doc-1', 'number' => 1]);
        Page::create(['uuid' => 'page-2', 'document_uuid' => 'doc-1', 'number' => 2]);
        $before = $this->counts();

        $exception = $this->assertThrown(MissingPrimaryKeyGeneratorException::class, function () use ($document) {
            DeepReplicate::for($document)->copy('pages')->replicate();
        });
        $this->assertStringContainsString('[uuid]', $exception->getMessage());
        $this->assertSame($before, $this->counts());

        $generated = [];
        $result = DeepReplicate::for($document)
            ->copy('pages')
            ->generate('uuid', function () {
                return 'doc-2';
            })
            ->configure('pages', function (RelationRule $rule) use (&$generated) {
                $rule->generate('uuid', function (Page $page) use (&$generated) {
                    return $generated[] = $page->uuid.'-copy';
                });
            })
            ->replicate();

        $this->assertSame('doc-2', $result->model()->getKey());
        $this->assertSame(['page-1-copy', 'page-2-copy'], $generated);
        $this->assertSame(['page-1-copy', 'page-2-copy'], Page::query()->where('document_uuid', 'doc-2')->orderBy('number')->pluck('uuid')->all());
        $this->assertSame(['page-1', 'page-2'], Page::query()->where('document_uuid', 'doc-1')->orderBy('number')->pluck('uuid')->all());
    }

    public function test_native_unique_ids_are_regenerated_once_per_copy(): void
    {
        $order = $this->seedOrder();
        Ticket::create(['id' => 't-1', 'reference' => 'R-1', 'order_id' => $order->id, 'subject' => 'Help']);
        Ticket::$generated = 0;

        $result = DeepReplicate::for($order)->copy('tickets')->replicate();
        $ticket = $result->model()->tickets()->firstOrFail();

        $this->assertSame(2, Ticket::$generated);
        $this->assertNotSame('t-1', $ticket->id);
        $this->assertNotSame('R-1', $ticket->reference);
        $this->assertTrue(Str::isUuid($ticket->id));
        $this->assertSame('Help', $ticket->subject);
    }

    public function test_an_incrementing_primary_key_cannot_be_generated_or_transformed(): void
    {
        $order = $this->seedOrder();

        $this->assertThrown(ProtectedRelationshipAttributeException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->generate('id', function () {
                return 999;
            })->replicate();
        });

        $this->assertThrown(ProtectedRelationshipAttributeException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->transform(function (array $attributes) {
                $attributes['id'] = 999;

                return $attributes;
            })->replicate();
        });

        $this->assertSame(1, Order::count());
    }

    public function test_custom_timestamps_are_new_by_default_and_preserved_on_request(): void
    {
        Shelf::create(['code' => 'S-1', 'label' => 'Fiction']);
        $book = Book::create(['shelf_code' => 'S-1', 'isbn' => '111', 'title' => 'Dune']);
        DB::table('legacy_books')->where('book_no', $book->book_no)->update(['added_on' => '2001-01-01 00:00:00', 'changed_on' => '2002-02-02 00:00:00']);
        $book = $book->fresh();
        $isbn = 0;
        $generate = function () use (&$isbn) {
            return 'isbn-'.(++$isbn);
        };

        $fresh = DeepReplicate::for($book)->copy('shelf')->generate('isbn', $generate)->configure('shelf', function (RelationRule $rule) {
            $rule->with(['code' => 'S-2']);
        })->replicate()->model()->fresh();
        $kept = DeepReplicate::for($book)->reuse('shelf')->generate('isbn', $generate)->preserveTimestamps()->replicate()->model()->fresh();

        $this->assertNotSame('2001-01-01 00:00:00', (string) $fresh->added_on);
        $this->assertNotNull($fresh->added_on);
        $this->assertSame('2001-01-01 00:00:00', $kept->added_on->format('Y-m-d H:i:s'));
        $this->assertSame('2002-02-02 00:00:00', $kept->changed_on->format('Y-m-d H:i:s'));
    }

    public function test_preserve_timestamps_applies_to_relation_copies_unless_a_path_overrides_it(): void
    {
        $order = $this->seedOrder();
        DB::table('orders')->update(['created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-02 00:00:00']);
        DB::table('order_items')->update(['created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-02 00:00:00']);
        DB::table('item_options')->update(['created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-02 00:00:00']);

        $copy = DeepReplicate::for($order->fresh())
            ->copy('items.options')
            ->preserveTimestamps()
            ->configure('items.options', function (RelationRule $rule) {
                $rule->preserveTimestamps(false);
            })
            ->replicate()
            ->model();

        $this->assertSame('2000-01-01 00:00:00', $copy->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(['2000-01-02 00:00:00'], $copy->items()->get()->map(function ($item) {
            return $item->updated_at->format('Y-m-d H:i:s');
        })->unique()->values()->all());
        $this->assertNotContains('2000-01-01 00:00:00', DB::table('item_options')->whereIn('order_item_id', $copy->items()->pluck('id'))->pluck('created_at')->all());
    }

    public function test_soft_deleted_roots_are_restored_by_default_and_kept_deleted_on_request(): void
    {
        $order = $this->seedOrder();
        $order->delete();
        $trashed = Order::withTrashed()->findOrFail($order->id);

        $restored = DeepReplicate::for($trashed)->copy('items')->replicate()->model();
        $kept = DeepReplicate::for($trashed)->copy('items')->preserveSoftDelete()->replicate()->model();

        $this->assertNull($restored->fresh()->deleted_at);
        $this->assertNotNull(Order::withTrashed()->findOrFail($kept->id)->deleted_at);
        $this->assertNull(Order::query()->find($kept->id));
    }

    public function test_soft_deleted_children_need_without_global_scopes(): void
    {
        $order = $this->seedOrder();
        $order->items()->where('title', 'Desk')->firstOrFail()->delete();

        $scoped = DeepReplicate::for($order)->copy('items')->replicate()->model();
        $unscoped = DeepReplicate::for($order)->copy('items')->withoutGlobalScopes()->replicate()->model();
        $preserved = DeepReplicate::for($order)->copy('items')->withoutGlobalScopes()->preserveSoftDelete()->replicate()->model();

        $this->assertSame(['Lamp'], OrderItem::withTrashed()->where('order_id', $scoped->id)->pluck('title')->all());
        $this->assertSame(['Desk', 'Lamp'], OrderItem::query()->where('order_id', $unscoped->id)->orderBy('title')->pluck('title')->all());
        $this->assertSame(['Lamp'], OrderItem::query()->where('order_id', $preserved->id)->pluck('title')->all());
        $this->assertSame(['Desk'], OrderItem::onlyTrashed()->where('order_id', $preserved->id)->pluck('title')->all());
    }

    public function test_root_and_path_modifiers_run_in_order_and_stay_isolated(): void
    {
        $order = $this->seedOrder();
        $calls = [];

        $result = DeepReplicate::for($order)
            ->copy('items')
            ->exclude(['external_ref'])
            ->with(['status' => 'draft'])
            ->generate('slug', function (Order $source, ReplicationContext $context) use (&$calls) {
                $calls[] = 'generate:'.($context->path() ?? 'root');

                return 'copy-of-'.$source->id;
            })
            ->transform(function (array $attributes, Order $source, ReplicationContext $context) use (&$calls) {
                $calls[] = 'transform:'.($context->path() ?? 'root');
                $this->assertSame('draft', $attributes['status']);
                $this->assertSame('copy-of-'.$source->id, $attributes['slug']);
                $this->assertArrayNotHasKey('external_ref', $attributes);
                $attributes['number'] .= '-COPY';

                return $attributes;
            })
            ->configure('items', function (RelationRule $rule) use (&$calls) {
                $rule->exclude(['external_id'])
                    ->with(['state' => 'pending'])
                    ->generate('sku', function (OrderItem $item, ReplicationContext $context) use (&$calls) {
                        $calls[] = 'generate:'.$context->path().':'.$context->depth();

                        return 'SKU-'.$item->id;
                    })
                    ->transform(function (array $attributes) use (&$calls) {
                        $calls[] = 'transform:items';
                        $attributes['title'] .= ' (copy)';

                        return $attributes;
                    });
            })
            ->replicate();

        $copy = $result->model()->fresh();

        $this->assertSame(['generate:root', 'transform:root', 'generate:items:1', 'transform:items', 'generate:items:1', 'transform:items'], $calls);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->external_ref);
        $this->assertSame('copy-of-'.$order->id, $copy->slug);
        $this->assertSame('A-100-COPY', $copy->number);
        $this->assertSame(['Lamp (copy)', 'Desk (copy)'], $copy->items()->orderBy('id')->pluck('title')->all());
        $this->assertSame(['pending'], $copy->items()->distinct()->pluck('state')->all());
        $this->assertSame([null], $copy->items()->distinct()->pluck('external_id')->all());
        $this->assertSame('EXT-1', $order->fresh()->external_ref);
        $this->assertSame(['shipped'], $order->items()->distinct()->pluck('state')->all());
    }

    public function test_unique_values_fail_with_rollback_unless_generated(): void
    {
        $order = $this->seedOrder('order-a-100');
        $order->items()->orderBy('id')->firstOrFail()->update(['sku' => 'SKU-1']);
        $before = $this->counts();

        $this->assertThrown(QueryException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->replicate();
        });
        $this->assertSame($before, $this->counts());

        $this->assertThrown(QueryException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->generate('slug', function () {
                return 'order-a-101';
            })->replicate();
        });
        $this->assertSame($before, $this->counts());

        $copy = DeepReplicate::for($order)
            ->copy('items')
            ->generate('slug', function () {
                return 'order-a-101';
            })
            ->configure('items', function (RelationRule $rule) {
                $rule->generate('sku', function (OrderItem $item) {
                    return $item->sku === null ? null : $item->sku.'-2';
                });
            })
            ->replicate()
            ->model();

        $this->assertSame('order-a-101', $copy->slug);
        $this->assertSame(['SKU-1-2'], $copy->items()->whereNotNull('sku')->pluck('sku')->all());
        $this->assertSame('order-a-100', $order->fresh()->slug);
    }

    public function test_casts_mutators_hidden_and_guarded_attributes_are_copied_raw(): void
    {
        $order = $this->seedOrder();
        $raw = DB::table('orders')->where('id', $order->id)->first();

        $copy = DeepReplicate::for($order)->copy('items')->replicate()->model();
        $copyRaw = DB::table('orders')->where('id', $copy->id)->first();

        $this->assertSame($raw->meta, $copyRaw->meta);
        $this->assertSame($raw->secret, $copyRaw->secret);
        $this->assertSame('top secret', $copy->fresh()->secret);
        $this->assertSame(['gift' => true, 'notes' => ['wrap']], $copy->fresh()->meta);
        $this->assertSame('99.50', $copy->fresh()->total);
        $this->assertSame('A-100', $copy->number);
        $this->assertEquals($order->customer_id, $copy->customer_id);
        $this->assertArrayNotHasKey('secret', $copy->toArray());
    }

    public function test_with_runs_mutators_and_casts(): void
    {
        $order = $this->seedOrder();

        $copy = DeepReplicate::for($order)->copy('items')->with([
            'number' => 'b-200',
            'secret' => 'changed',
            'meta' => ['gift' => false],
        ])->replicate()->model()->fresh();

        $this->assertSame('B-200', $copy->number);
        $this->assertSame('changed', $copy->secret);
        $this->assertSame(['gift' => false], $copy->meta);
        $this->assertNotSame('changed', DB::table('orders')->where('id', $copy->id)->value('secret'));
    }

    public function test_replication_works_while_silently_discarded_attributes_are_prevented(): void
    {
        $order = $this->seedOrder();
        $prevent = method_exists(Model::class, 'preventSilentlyDiscardingAttributes');

        if ($prevent) {
            Model::preventSilentlyDiscardingAttributes();
        }

        try {
            $copy = DeepReplicate::for($order)->copy('items')->with(['status' => 'draft'])->replicate()->model();
        } finally {
            if ($prevent) {
                Model::preventSilentlyDiscardingAttributes(false);
            }
        }

        $this->assertSame('draft', $copy->status);
        $this->assertEquals($order->customer_id, $copy->customer_id);
        $this->assertSame('paid', $order->fresh()->status);
    }

    public function test_copies_never_carry_the_source_loaded_relations(): void
    {
        $order = $this->seedOrder();
        $order->load('items.options', 'payments', 'customer');

        $result = DeepReplicate::for($order)->copy('items')->replicate();

        $this->assertSame([], $result->model()->getRelations());

        foreach ($result->map()->all() as $copy) {
            $this->assertSame([], $copy->getRelations());
        }

        $this->assertTrue($order->relationLoaded('payments'));
    }

    public function test_originals_and_their_pivots_are_unchanged_by_every_strategy(): void
    {
        $order = $this->seedOrder();
        $tables = ['customers', 'products', 'orders', 'order_items', 'item_options', 'payments', 'tags', 'order_item_tag'];
        $before = [];

        foreach ($tables as $table) {
            $before[$table] = $this->rows($table);
        }

        DeepReplicate::for($order)
            ->copy('items', 'items.options', 'items.tags')
            ->reuse('items.product', 'customer')
            ->skip('payments', 'items.plainTags')
            ->replicate();

        foreach ($tables as $table) {
            $this->assertSame($before[$table], array_slice($this->rows($table), 0, count($before[$table])), $table);
        }
    }

    public function test_managed_linking_attributes_are_protected(): void
    {
        $order = $this->seedOrder();
        $before = $this->counts();

        $cases = [
            'with on a reused belongs-to key' => function () use ($order) {
                DeepReplicate::for($order)->reuse('customer')->with(['customer_id' => 99])->replicate();
            },
            'exclude a reused belongs-to key' => function () use ($order) {
                DeepReplicate::for($order)->reuse('customer')->exclude(['customer_id'])->replicate();
            },
            'generate a copied belongs-to key' => function () use ($order) {
                DeepReplicate::for($order)->copy('customer')->generate('customer_id', function () {
                    return 1;
                })->replicate();
            },
            'transform a reused belongs-to key' => function () use ($order) {
                DeepReplicate::for($order)->reuse('customer')->transform(function (array $attributes) {
                    unset($attributes['customer_id']);

                    return $attributes;
                })->replicate();
            },
            'with on a child foreign key' => function () use ($order) {
                DeepReplicate::for($order)->copy('items')->configure('items', function (RelationRule $rule) {
                    $rule->with(['order_id' => 999]);
                })->replicate();
            },
            'transform a child foreign key' => function () use ($order) {
                DeepReplicate::for($order)->copy('items')->configure('items', function (RelationRule $rule) {
                    $rule->transform(function (array $attributes) {
                        $attributes['order_id'] = 999;

                        return $attributes;
                    });
                })->replicate();
            },
            'beforeModel changing a managed key' => function () use ($order) {
                DeepReplicate::for($order)->copy('items')->beforeModel(function (Model $original, Model $copy) {
                    if ($copy instanceof OrderItem) {
                        $copy->setAttribute('order_id', 999);
                    }
                })->replicate();
            },
        ];

        foreach ($cases as $name => $case) {
            $exception = $this->assertThrown(ProtectedRelationshipAttributeException::class, $case);
            $this->assertMatchesRegularExpression('/\[(customer_id|order_id)\]/', $exception->getMessage(), $name);
        }

        $this->assertSame($before, $this->counts());
    }

    public function test_rewriting_a_managed_key_with_its_own_value_is_not_a_change(): void
    {
        $order = $this->seedOrder();

        $copy = DeepReplicate::for($order)->reuse('customer')->transform(function (array $attributes) {
            // An integer where the driver may have returned a numeric string.
            $attributes['customer_id'] = (int) $attributes['customer_id'];

            return $attributes;
        })->replicate()->model();

        $this->assertEquals($order->customer_id, $copy->customer_id);
    }

    public function test_unmentioned_root_foreign_keys_may_be_changed_deliberately(): void
    {
        $order = $this->seedOrder();
        $other = \Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Customer::create(['name' => 'Bob']);

        $copy = DeepReplicate::for($order)->copy('items')->with(['customer_id' => $other->id, 'assigned_customer_id' => $other->id])->replicate()->model();

        $this->assertEquals($other->id, $copy->customer_id);
        $this->assertEquals($other->id, $copy->assigned_customer_id);
    }

    public function test_before_model_cannot_switch_the_connection(): void
    {
        $order = $this->seedOrder();

        $this->assertThrown(CrossConnectionReplicationException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->beforeModel(function (Model $original, Model $copy) {
                $copy->setConnection('secondary');
            })->replicate();
        });

        $this->assertSame(1, Order::count());
    }

    public function test_transform_must_return_an_attribute_array(): void
    {
        $order = $this->seedOrder();

        $this->assertThrown(\UnexpectedValueException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->transform(function () {
                return 'nope';
            })->replicate();
        });

        $this->assertSame(1, Order::count());
    }
}
