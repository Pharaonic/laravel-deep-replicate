## Basic Usage

### Why native replicate() is not enough

`$order->replicate()` returns one unsaved `Order` with the same attributes. It copies no related records, and it may carry the source's loaded relations into the clone. Deep Replicate builds on that primitive. It replicates every selected model, links the copies to each other, and saves the whole graph in one transaction.

### Three equivalent entry points

The model trait, the facade, and the injected service all return the same `ReplicationBuilder`. Nothing is read or written until you call `replicate()` or `dryRun()`.

```php
use Pharaonic\Laravel\DeepReplicate\DeepReplicator;
use Pharaonic\Laravel\DeepReplicate\Facades\DeepReplicate;

// Model trait: the first argument is required.
$result = $order->deepReplicate('items')->replicate();

// Facade
$result = DeepReplicate::for($order)->copy('items')->replicate();

// Dependency injection
public function duplicate(Order $order, DeepReplicator $replicator)
{
    return $replicator->for($order, ['items'])->replicate()->model();
}
```

### A selection is always required

There is no root-only deep replication. Use Laravel's `replicate()` for that.

```php
$order->deepReplicate();                            // ArgumentCountError
$order->deepReplicate([]);                          // EmptyRelationshipSelectionException
DeepReplicate::for($order)->replicate();            // EmptyRelationshipSelectionException
DeepReplicate::for($order)->skip('payments')->replicate(); // EmptyRelationshipSelectionException
```

At least one path must be selected as `copy` or `reuse`. Preloading relations never selects them.

### Selecting paths

Positional paths are copied. An associative array assigns `copy`, `reuse` or `skip`; strategy names are case-insensitive. Nested paths are flat dot paths.

```php
$order->deepReplicate('items');
$order->deepReplicate('items', 'customer');
$order->deepReplicate(['items', 'customer']);
$order->deepReplicate(['items' => 'copy', 'customer' => 'reuse', 'payments' => 'skip']);

DeepReplicate::for($order, [
    'items' => 'copy',
    'items.options' => 'copy',
    'items.product' => 'reuse',
    'customer' => 'reuse',
    'payments' => 'skip',
])->replicate();
```

The fluent methods do the same and can be mixed with the array forms:

- ===Trait

  ```php
  $result = $order->deepReplicate('items', 'items.options')
      ->reuse('items.product', 'customer')
      ->skip('payments')
      ->replicate();

  $newOrder = $result->model();   // persisted root
  $map = $result->map();          // original => copy
  $stats = $result->stats();      // counts
  ```

- ===Facade

  ```php
  $result = DeepReplicate::for($order)
      ->copy('items', 'items.options')
      ->reuse('items.product', 'customer')
      ->skip('payments')
      ->replicate();

  $newOrder = $result->model();   // persisted root
  $map = $result->map();          // original => copy
  $stats = $result->stats();      // counts
  ```

Every example in these docs works with both APIs. The trait's arguments are the facade's first `copy()` (or `relations()` array) call:

| Trait | Facade |
| --- | --- |
| `$order->deepReplicate('items')` | `DeepReplicate::for($order)->copy('items')` |
| `$order->deepReplicate('items', 'customer')` | `DeepReplicate::for($order)->copy('items', 'customer')` |
| `$order->deepReplicate(['customer' => 'reuse'])` | `DeepReplicate::for($order)->reuse('customer')` |
| `$order->deepReplicate([...])` | `DeepReplicate::for($order, [...])` |

| Strategy | Effect |
| --- | --- |
| `copy` | Creates new related records and links them to the new parent. |
| `reuse` | Links the new parent to the existing records, without changing them. |
| `skip` | No query and no link. A foreign key already held by the copy is kept. |
