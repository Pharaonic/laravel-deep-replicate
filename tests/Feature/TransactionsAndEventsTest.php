<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\DeepReplicate\Exceptions\AfterCommitUnavailableException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\CrossConnectionReplicationException;
use Pharaonic\Laravel\DeepReplicate\Exceptions\ReplicationCancelledException;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;
use Pharaonic\Laravel\DeepReplicate\RelationRule;
use Pharaonic\Laravel\DeepReplicate\ReplicationContext;
use Pharaonic\Laravel\DeepReplicate\ReplicationResult;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\ItemOption;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\Order;
use Pharaonic\Laravel\DeepReplicate\Tests\Fixtures\Models\OrderItem;

test('a successful replication commits once', function () {
    $order = $this->seedOrder();
    $levels = [];

    $result = DeepReplicate::for($order)->copy('items.options')->beforeReplicate(function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    })->replicate();

    expect($levels)->toBe([1]);
    expect(DB::transactionLevel())->toBe(0);
    expect(Order::query()->whereKey($result->model()->getKey())->exists())->toBeTrue();
});

test('a failing insert leaves no new records or pivots', function () {
    $order = $this->seedOrder();
    $before = $this->counts();
    $created = 0;

    ItemOption::creating(function () use (&$created) {
        if (++$created === 2) {
            throw new RuntimeException('second option fails');
        }
    });

    $exception = $this->assertThrows(RuntimeException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items.options', 'items.plainTags')->replicate();
    });

    expect($exception->getMessage())->toBe('second option fails');
    expect($this->counts())->toBe($before);
});

test('a failing pivot insert rolls back the copied models', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $this->assertThrows(RuntimeException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->reuse('items.plainTags')->transformPivot('items.plainTags', function () {
            throw new RuntimeException('pivot fails');
        })->replicate();
    });

    expect($this->counts())->toBe($before);
});

test('a cancelling listener rolls back and reports the path', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    OrderItem::creating(function () {
        return false;
    });

    $exception = $this->assertThrows(ReplicationCancelledException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->replicate();
    });

    expect($exception->getMessage())->toContain('[items]');
    expect($this->counts())->toBe($before);
});

test('eloquent events fire by default', function () {
    $order = $this->seedOrder();
    $events = $this->recordModelEvents([Order::class, OrderItem::class]);

    DeepReplicate::for($order)->copy('items')->replicate();

    $sequence = (array) $events;
    expect(array_slice($sequence, 0, 2))->toBe(['OrderItem.retrieved', 'OrderItem.retrieved']);
    expect(array_slice($sequence, 2))->toBe([
        'Order.replicating', 'OrderItem.replicating', 'OrderItem.replicating',
        'Order.saving', 'Order.creating', 'Order.created', 'Order.saved',
        'OrderItem.saving', 'OrderItem.creating', 'OrderItem.created', 'OrderItem.saved',
        'OrderItem.saving', 'OrderItem.creating', 'OrderItem.created', 'OrderItem.saved',
    ]);
});

test('without events suppresses every model event and restores the dispatcher', function () {
    $order = $this->seedOrder();
    $dispatcher = Model::getEventDispatcher();
    $events = $this->recordModelEvents([Order::class, OrderItem::class, ItemOption::class]);

    DeepReplicate::for($order)->copy('items.options')->withoutEvents()->replicate();

    expect((array) $events)->toBe([]);
    expect(Model::getEventDispatcher())->toBe($dispatcher);

    Order::query()->firstOrFail();
    expect((array) $events)->toBe(['Order.retrieved']);
});

test('path without events only silences that path', function () {
    $order = $this->seedOrder();
    $events = $this->recordModelEvents([Order::class, OrderItem::class, ItemOption::class]);

    DeepReplicate::for($order)->copy('items.options')->configure('items', function (RelationRule $rule) {
        $rule->withoutEvents();
    })->replicate();

    $names = array_unique(array_map(function (string $event) {
        return explode('.', $event)[1] === 'retrieved' ? $event : explode('.', $event)[0].'.write';
    }, (array) $events));

    expect($names)->toContain('Order.write');
    expect($names)->toContain('ItemOption.write');
    expect($names)->not->toContain('OrderItem.write');
    expect($this->app['events'])->toBe(Model::getEventDispatcher());
});

test('lifecycle callbacks fire once each in traversal order', function () {
    $order = $this->seedOrder();
    $log = [];

    $result = DeepReplicate::for($order)
        ->copy('items')
        ->reuse('customer')
        ->beforeReplicate(function (ReplicationContext $context) use (&$log) {
            $log[] = 'beforeReplicate:'.$context->depth();
            expect($context->replica())->toBeNull();
        })
        ->beforeModel(function (Model $original, Model $copy, ReplicationContext $context) use (&$log) {
            expect($copy->exists)->toBeFalse();
            $log[] = 'beforeModel:'.class_basename($copy).':'.($context->path() ?? 'root');
        })
        ->afterModel(function (Model $original, Model $copy, ReplicationContext $context) use (&$log) {
            expect($copy->exists)->toBeTrue();
            expect($context->replica())->not->toBeNull();

            if ($copy instanceof OrderItem) {
                expect($copy->order_id)->toEqual($context->replica()->getKey());
            }

            $log[] = 'afterModel:'.class_basename($copy).':'.$original->getKey();
        })
        ->afterReplicate(function (ReplicationResult $result, ReplicationContext $context) use (&$log) {
            expect(DB::transactionLevel())->toBe(1);
            $log[] = 'afterReplicate:'.$result->stats()->copiedModels();
        })
        ->afterCommit(function (ReplicationResult $result) use (&$log) {
            expect(DB::transactionLevel())->toBe(0);
            $log[] = 'afterCommit';
        })
        ->replicate();

    [$first, $second] = $order->items()->orderBy('id')->pluck('id')->all();

    expect($log)->toBe([
        'beforeReplicate:0',
        'beforeModel:Order:root',
        'beforeModel:OrderItem:items',
        'beforeModel:OrderItem:items',
        'afterModel:Order:'.$order->id,
        'afterModel:OrderItem:'.$first,
        'afterModel:OrderItem:'.$second,
        'afterReplicate:3',
        'afterCommit',
    ]);
    expect($result->model()->exists)->toBeTrue();
});

test('a failing pre commit callback rolls back', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    foreach (['beforeReplicate', 'beforeModel', 'afterModel', 'afterReplicate'] as $hook) {
        $committed = false;

        $this->assertThrows(RuntimeException::class, function () use ($order, $hook, &$committed) {
            DeepReplicate::for($order)->copy('items')->{$hook}(function () use ($hook) {
                throw new RuntimeException($hook);
            })->afterCommit(function () use (&$committed) {
                $committed = true;
            })->replicate();
        });

        $this->assertFalse($committed, $hook);
        $this->assertSame($before, $this->counts(), $hook);
    }
});

test('an after commit failure cannot undo the commit', function () {
    $order = $this->seedOrder();

    $this->assertThrows(RuntimeException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->afterCommit(function () {
            throw new RuntimeException('after commit');
        })->replicate();
    });

    expect(Order::count())->toBe(2);
    expect(DB::transactionLevel())->toBe(0);
});

test('an outer transaction rollback discards the replica', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    DB::beginTransaction();
    $result = DeepReplicate::for($order)->copy('items.options')->replicate();
    expect(Order::count())->toBe(2);
    expect(DB::transactionLevel())->toBe(1);
    DB::rollBack();

    expect($this->counts())->toBe($before);
    expect(Order::query()->whereKey($result->model()->getKey())->exists())->toBeFalse();
});

test('after commit never fires before the outer transaction commits', function () {
    $order = $this->seedOrder();
    $before = $this->counts();
    $fired = 0;
    $native = method_exists(Connection::class, 'afterCommit');

    DB::beginTransaction();

    try {
        DeepReplicate::for($order)->copy('items')->afterCommit(function () use (&$fired) {
            $fired++;
        })->replicate();

        $this->assertTrue($native, 'Only connections with a native afterCommit() may accept the callback.');
        expect($fired)->toBe(0);
        DB::commit();
        expect($fired)->toBe(1);
    } catch (AfterCommitUnavailableException $exception) {
        expect($native)->toBeFalse();
        expect($fired)->toBe(0);
        expect($this->counts())->toBe($before);
        expect(DB::transactionLevel())->toBe(1);
        DB::rollBack();
    }

    expect($fired)->toBe($native ? 1 : 0);
});

test('after commit is dropped when the outer transaction rolls back', function () {
    if (! method_exists(Connection::class, 'afterCommit')) {
        $order = $this->seedOrder();

        DB::beginTransaction();
        $this->assertThrows(AfterCommitUnavailableException::class, function () use ($order) {
            DeepReplicate::for($order)->copy('items')->afterCommit(function () {
            })->replicate();
        });
        DB::rollBack();

        expect(Order::count())->toBe(1);

        return;
    }

    $order = $this->seedOrder();
    $fired = false;

    DB::beginTransaction();
    DeepReplicate::for($order)->copy('items')->afterCommit(function () use (&$fired) {
        $fired = true;
    })->replicate();
    DB::rollBack();

    expect($fired)->toBeFalse();
    expect(Order::count())->toBe(1);
});

test('a graph spanning connections is rejected before any write', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $exception = $this->assertThrows(CrossConnectionReplicationException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items', 'notes')->replicate();
    });

    expect($exception->getMessage())->toContain('[secondary]');
    expect($exception->getMessage())->toContain('[testing]');
    expect($this->counts())->toBe($before);
});

test('naming the default connection is not another connection', function () {
    $order = $this->seedOrder();

    $result = DeepReplicate::for($order)->copy('localNotes')->replicate();

    expect($result->stats()->copiedModels())->toBe(1 + 2);
});

test('replicate plans again inside its transaction', function () {
    $order = $this->seedOrder();
    $builder = DeepReplicate::for($order)->copy('items');

    expect($builder->dryRun()->estimatedModels())->toBe(3);

    OrderItem::create(['order_id' => $order->id, 'title' => 'Chair']);

    $result = $builder->replicate();

    expect($result->stats()->copiedModels())->toBe(4);
    expect($result->model()->items()->count())->toBe(3);
});

test('records visible inside the transaction are rolled back on failure', function () {
    $order = $this->seedOrder();
    $before = $this->counts();

    $this->assertThrows(RuntimeException::class, function () use ($order) {
        DeepReplicate::for($order)->copy('items')->afterReplicate(function (ReplicationResult $result) {
            expect(Order::query()->whereKey($result->model()->getKey())->exists())->toBeTrue();

            throw new RuntimeException('abort');
        })->replicate();
    });

    expect($this->counts())->toBe($before);
});
