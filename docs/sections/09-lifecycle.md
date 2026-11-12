## Lifecycle

### Eloquent events

Events stay on by default. Every copy fires `replicating`, `saving`, `creating`, `created` and `saved`, and records read for the plan fire `retrieved`.

- ===Trait

  ```php
  $order->deepReplicate('items')->withoutEvents()->replicate(); // no model events at all

  $order->deepReplicate('items.options')
      ->configure('items', fn (RelationRule $rule) => $rule->withoutEvents()) // items only
      ->replicate();
  ```

- ===Facade

  ```php
  DeepReplicate::for($order)->copy('items')->withoutEvents()->replicate(); // no model events at all

  DeepReplicate::for($order)
      ->copy('items.options')
      ->configure('items', fn (RelationRule $rule) => $rule->withoutEvents()) // items only
      ->replicate();
  ```

`withoutEvents()` uses Laravel's own `Model::withoutEvents()` for the duration of the operation. Listener registration keeps working, and the original dispatcher is always restored. A path's `withoutEvents()` wraps only the replication and the insert of that path's copies (and of its custom pivots), never the root or other paths.

### Callbacks

| Callback | Runs | Receives |
| --- | --- | --- |
| `beforeReplicate` | Once, after planning, before the first write, inside the transaction. | `ReplicationContext` |
| `beforeModel` | Once per copy, fully prepared and unsaved, just before its insert. | original, copy, context |
| `afterModel` | Once per copy, in traversal order, after all inserts, links and pivots. | original, copy, context |
| `afterReplicate` | Once, after every `afterModel`, before the commit. | `ReplicationResult`, context |
| `afterCommit` | Once, after the outermost transaction commits. | `ReplicationResult` |

A `beforeModel` callback may change the copy, but not its primary key, its linking keys or its connection.

- ===Trait

  ```php
  $order->deepReplicate('items')
      ->afterModel(function (Model $original, Model $copy, ReplicationContext $context): void {
          // $copy->exists === true, links resolved
      })
      ->afterCommit(function (ReplicationResult $result): void {
          dispatch(new NotifyOrderCopied($result->model()->getKey()));
      })
      ->replicate();
  ```

- ===Facade

  ```php
  DeepReplicate::for($order)
      ->copy('items')
      ->afterModel(function (Model $original, Model $copy, ReplicationContext $context): void {
          // $copy->exists === true, links resolved
      })
      ->afterCommit(function (ReplicationResult $result): void {
          dispatch(new NotifyOrderCopied($result->model()->getKey()));
      })
      ->replicate();
  ```

An exception in any callback before the commit rolls the whole replication back. An exception in `afterCommit` is thrown to you, but the data is already committed and stays.

### afterCommit and your own transactions

When the replication opens the outermost transaction, `afterCommit` runs right after it commits. Inside a transaction you opened, the callbacks wait for your outermost commit and are dropped if you roll back. That needs `Connection::afterCommit()`, which Laravel ships from 8.x. On Laravel 6 and 7, registering `afterCommit` inside your transaction throws `AfterCommitUnavailableException` before anything is written. The callback never runs early.

:::warning Side effects in callbacks
A rollback cannot undo mail, HTTP calls, queued jobs or files written by callbacks and observers. Do that work in `afterCommit`, or through an outbox table written inside the transaction.
:::

### The context

`ReplicationContext` gives `root()` (the original root), `replica()` (the new root, null before it is inserted), `path()` (null for the root), `depth()` (0 for the root) and `connectionName()`.
