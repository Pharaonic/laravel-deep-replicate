<?php

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

test('string keys need an explicit generator', function () {
    $document = Document::create(['uuid' => 'doc-1', 'title' => 'Spec']);
    Page::create(['uuid' => 'page-1', 'document_uuid' => 'doc-1', 'number' => 1]);
    Page::create(['uuid' => 'page-2', 'document_uuid' => 'doc-1', 'number' => 2]);
    $before = $this->counts();

    $exception = $this->assertThrows(MissingPrimaryKeyGeneratorException::class, function () use ($document) {
        DeepReplicate::for($document)->copy('pages')->replicate();
    });
    expect($exception->getMessage())->toContain('[uuid]');
    expect($this->counts())->toBe($before);

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

    expect($result->model()->getKey())->toBe('doc-2');
    expect($generated)->toBe(['page-1-copy', 'page-2-copy']);
    expect(Page::query()->where('document_uuid', 'doc-2')->orderBy('number')->pluck('uuid')->all())->toBe(['page-1-copy', 'page-2-copy']);
    expect(Page::query()->where('document_uuid', 'doc-1')->orderBy('number')->pluck('uuid')->all())->toBe(['page-1', 'page-2']);
});

test('native unique ids are regenerated once per copy', function () {
    $order = $this->seedOrder();
    Ticket::create(['id' => 't-1', 'reference' => 'R-1', 'order_id' => $order->id, 'subject' => 'Help']);
    Ticket::$generated = 0;

    $result = DeepReplicate::for($order)->copy('tickets')->replicate();
    $ticket = $result->model()->tickets()->firstOrFail();

    expect(Ticket::$generated)->toBe(2);
    expect($ticket->id)->not->toBe('t-1');
    expect($ticket->reference)->not->toBe('R-1');
    expect(Str::isUuid($ticket->id))->toBeTrue();
    expect($ticket->subject)->toBe('Help');
});

test('an incrementing primary key cannot be generated or transformed', function () {
    $order = $this->seedOrder();

    $this->assertThrows(ProtectedRelationshipAttributeException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->generate('id', function () {
            return 999;
        })->replicate();
    });

    $this->assertThrows(ProtectedRelationshipAttributeException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->transform(function (array $attributes) {
            $attributes['id'] = 999;

            return $attributes;
        })->replicate();
    });

    expect(Order::count())->toBe(1);
});

test('custom timestamps are new by default and preserved on request', function () {
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

    expect((string) $fresh->added_on)->not->toBe('2001-01-01 00:00:00');
    expect($fresh->added_on)->not->toBeNull();
    expect($kept->added_on->format('Y-m-d H:i:s'))->toBe('2001-01-01 00:00:00');
    expect($kept->changed_on->format('Y-m-d H:i:s'))->toBe('2002-02-02 00:00:00');
});

test('preserve timestamps applies to relation copies unless a path overrides it', function () {
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

    expect($copy->fresh()->created_at->format('Y-m-d H:i:s'))->toBe('2000-01-01 00:00:00');
    expect($copy->items()->get()->map(function ($item) {
        return $item->updated_at->format('Y-m-d H:i:s');
    })->unique()->values()->all())->toBe(['2000-01-02 00:00:00']);
    expect(DB::table('item_options')->whereIn('order_item_id', $copy->items()->pluck('id'))->pluck('created_at')->all())->not->toContain('2000-01-01 00:00:00');
});

test('soft deleted roots are restored by default and kept deleted on request', function () {
    $order = $this->seedOrder();
    $order->delete();
    $trashed = Order::withTrashed()->findOrFail($order->id);

    $restored = DeepReplicate::for($trashed)->copy('items')->replicate()->model();
    $kept = DeepReplicate::for($trashed)->copy('items')->preserveSoftDelete()->replicate()->model();

    expect($restored->fresh()->deleted_at)->toBeNull();
    expect(Order::withTrashed()->findOrFail($kept->id)->deleted_at)->not->toBeNull();
    expect(Order::query()->find($kept->id))->toBeNull();
});

test('soft deleted children need without global scopes', function () {
    $order = $this->seedOrder();
    $order->items()->where('title', 'Desk')->firstOrFail()->delete();

    $scoped = DeepReplicate::for($order)->copy('items')->replicate()->model();
    $unscoped = DeepReplicate::for($order)->copy('items')->withoutGlobalScopes()->replicate()->model();
    $preserved = DeepReplicate::for($order)->copy('items')->withoutGlobalScopes()->preserveSoftDelete()->replicate()->model();

    expect(OrderItem::withTrashed()->where('order_id', $scoped->id)->pluck('title')->all())->toBe(['Lamp']);
    expect(OrderItem::query()->where('order_id', $unscoped->id)->orderBy('title')->pluck('title')->all())->toBe(['Desk', 'Lamp']);
    expect(OrderItem::query()->where('order_id', $preserved->id)->pluck('title')->all())->toBe(['Lamp']);
    expect(OrderItem::onlyTrashed()->where('order_id', $preserved->id)->pluck('title')->all())->toBe(['Desk']);
});

test('root and path modifiers run in order and stay isolated', function () {
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
            expect($attributes['status'])->toBe('draft');
            expect($attributes['slug'])->toBe('copy-of-'.$source->id);
            expect($attributes)->not->toHaveKey('external_ref');
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

    expect($calls)->toBe(['generate:root', 'transform:root', 'generate:items:1', 'transform:items', 'generate:items:1', 'transform:items']);
    expect($copy->status)->toBe('draft');
    expect($copy->external_ref)->toBeNull();
    expect($copy->slug)->toBe('copy-of-'.$order->id);
    expect($copy->number)->toBe('A-100-COPY');
    expect($copy->items()->orderBy('id')->pluck('title')->all())->toBe(['Lamp (copy)', 'Desk (copy)']);
    expect($copy->items()->distinct()->pluck('state')->all())->toBe(['pending']);
    expect($copy->items()->distinct()->pluck('external_id')->all())->toBe([null]);
    expect($order->fresh()->external_ref)->toBe('EXT-1');
    expect($order->items()->distinct()->pluck('state')->all())->toBe(['shipped']);
});

test('unique values fail with rollback unless generated', function () {
    $order = $this->seedOrder('order-a-100');
    $order->items()->orderBy('id')->firstOrFail()->update(['sku' => 'SKU-1']);
    $before = $this->counts();

    $this->assertThrows(QueryException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->replicate();
    });
    expect($this->counts())->toBe($before);

    $this->assertThrows(QueryException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->generate('slug', function () {
            return 'order-a-101';
        })->replicate();
    });
    expect($this->counts())->toBe($before);

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

    expect($copy->slug)->toBe('order-a-101');
    expect($copy->items()->whereNotNull('sku')->pluck('sku')->all())->toBe(['SKU-1-2']);
    expect($order->fresh()->slug)->toBe('order-a-100');
});

test('casts mutators hidden and guarded attributes are copied raw', function () {
    $order = $this->seedOrder();
    $raw = DB::table('orders')->where('id', $order->id)->first();

    $copy = DeepReplicate::for($order)->copy('items')->replicate()->model();
    $copyRaw = DB::table('orders')->where('id', $copy->id)->first();

    expect($copyRaw->meta)->toBe($raw->meta);
    expect($copyRaw->secret)->toBe($raw->secret);
    expect($copy->fresh()->secret)->toBe('top secret');
    expect($copy->fresh()->meta)->toBe(['gift' => true, 'notes' => ['wrap']]);
    expect($copy->fresh()->total)->toBe('99.50');
    expect($copy->number)->toBe('A-100');
    expect($copy->customer_id)->toEqual($order->customer_id);
    expect($copy->toArray())->not->toHaveKey('secret');
});

test('with runs mutators and casts', function () {
    $order = $this->seedOrder();

    $copy = DeepReplicate::for($order)->copy('items')->with([
        'number' => 'b-200',
        'secret' => 'changed',
        'meta' => ['gift' => false],
    ])->replicate()->model()->fresh();

    expect($copy->number)->toBe('B-200');
    expect($copy->secret)->toBe('changed');
    expect($copy->meta)->toBe(['gift' => false]);
    expect(DB::table('orders')->where('id', $copy->id)->value('secret'))->not->toBe('changed');
});

test('replication works while silently discarded attributes are prevented', function () {
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

    expect($copy->status)->toBe('draft');
    expect($copy->customer_id)->toEqual($order->customer_id);
    expect($order->fresh()->status)->toBe('paid');
});

test('copies never carry the source loaded relations', function () {
    $order = $this->seedOrder();
    $order->load('items.options', 'payments', 'customer');

    $result = DeepReplicate::for($order)->copy('items')->replicate();

    expect($result->model()->getRelations())->toBe([]);

    foreach ($result->map()->all() as $copy) {
        expect($copy->getRelations())->toBe([]);
    }

    expect($order->relationLoaded('payments'))->toBeTrue();
});

test('originals and their pivots are unchanged by every strategy', function () {
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
});

test('managed linking attributes are protected', function () {
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
        $exception = $this->assertThrows(ProtectedRelationshipAttributeException::class, $case);
        $this->assertMatchesRegularExpression('/\[(customer_id|order_id)\]/', $exception->getMessage(), $name);
    }

    expect($this->counts())->toBe($before);
});

test('rewriting a managed key with its own value is not a change', function () {
    $order = $this->seedOrder();

    $copy = DeepReplicate::for($order)->reuse('customer')->transform(function (array $attributes) {
        // An integer where the driver may have returned a numeric string.
        $attributes['customer_id'] = (int) $attributes['customer_id'];

        return $attributes;
    })->replicate()->model();

    expect($copy->customer_id)->toEqual($order->customer_id);
});

test('unmentioned root foreign keys may be changed deliberately', function () {
    $order = $this->seedOrder();
    $other = \Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Customer::create(['name' => 'Bob']);

    $copy = DeepReplicate::for($order)->copy('items')->with(['customer_id' => $other->id, 'assigned_customer_id' => $other->id])->replicate()->model();

    expect($copy->customer_id)->toEqual($other->id);
    expect($copy->assigned_customer_id)->toEqual($other->id);
});

test('before model cannot switch the connection', function () {
    $order = $this->seedOrder();

    $this->assertThrows(CrossConnectionReplicationException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->beforeModel(function (Model $original, Model $copy) {
            $copy->setConnection('secondary');
        })->replicate();
    });

    expect(Order::count())->toBe(1);
});

test('transform must return an attribute array', function () {
    $order = $this->seedOrder();

    $this->assertThrows(\UnexpectedValueException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->transform(function () {
            return 'nope';
        })->replicate();
    });

    expect(Order::count())->toBe(1);
});
