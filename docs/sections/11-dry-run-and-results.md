## Dry Run and Results

### dryRun()

`dryRun()` returns a `ReplicationPlan`. It runs the planning queries only: no INSERT, UPDATE or DELETE, no model events (`retrieved` included), and no lifecycle callbacks, transforms or generators. Filters do run, because they decide the graph.

- ===Trait

  ```php
  $plan = $order->deepReplicate('items', 'items.options')
      ->reuse('items.product')
      ->dryRun();

  $plan->relationships();      // ['items' => 'copy', 'items.options' => 'copy', 'items.product' => 'reuse']
  $plan->stats();              // projected ReplicationStats
  $plan->estimatedModels();    // copies + reused records
  $plan->estimatedPivotRows();
  $plan->warnings();           // list of PlanningWarning (code(), path(), message())
  ```

- ===Facade

  ```php
  $plan = DeepReplicate::for($order)
      ->copy('items', 'items.options')
      ->reuse('items.product')
      ->dryRun();

  $plan->relationships();      // ['items' => 'copy', 'items.options' => 'copy', 'items.product' => 'reuse']
  $plan->stats();              // projected ReplicationStats
  $plan->estimatedModels();    // copies + reused records
  $plan->estimatedPivotRows();
  $plan->warnings();           // list of PlanningWarning (code(), path(), message())
  ```

The counts are a snapshot. The data may change before `replicate()` runs. A builder can run `dryRun()` any number of times before `replicate()`.

### ReplicationResult

- ===Trait

  ```php
  $result = $order->deepReplicate('items')->reuse('items.product')->replicate();

  $result->model();                    // the new, persisted root (relations not loaded)
  $result->map()->get($originalItem);  // its copy, or null
  $result->map()->isReused($product);  // true
  $result->map()->all();               // identity key => copy
  $result->map()->reused();            // identity key => reused original
  $result->stats()->toArray();
  ```

- ===Facade

  ```php
  $result = DeepReplicate::for($order)->copy('items')->reuse('items.product')->replicate();

  $result->model();                    // the new, persisted root (relations not loaded)
  $result->map()->get($originalItem);  // its copy, or null
  $result->map()->isReused($product);  // true
  $result->map()->all();               // identity key => copy
  $result->map()->reused();            // identity key => reused original
  $result->stats()->toArray();
  ```

Identity keys read `{connection}|{class}|{key type}:{key}`, for example `mysql|App\Item|integer:42`. Reused originals are never listed as copies.

| Stat | Meaning |
| --- | --- |
| `copiedModels()` | New models, the root included. A shared record counts once. |
| `reusedModels()` | Distinct reused records. |
| `skippedRelationships()` | Paths selected as skip. |
| `pivotRowsCreated()` | Pivot rows inserted (projected for a plan). |
| `maxDepthReached()` | The deepest level where a record was reached. |
| `durationMs()` | Duration of the operation (of the planning, for a plan). |

A builder is single-use. A second `replicate()`, or a `dryRun()` after `replicate()`, throws `BuilderAlreadyExecutedException`, even when the first call failed.

### Limits

| Method | Default | Enforced |
| --- | --- | --- |
| `maxDepth(int)` | 10 | Before any query, from the selected paths (root depth 0). |
| `maxModels(int)` | 10,000 | While planning: copies plus reused records, the root included. |
| `maxPivotRows(int)` | 50,000 | While planning. |
| `chunk(int)` | 500 | Parents per relation query and rows per pivot insert. |

A limit throws `ReplicationLimitExceededException` before the first write. Values below 1 throw `InvalidArgumentException`.

### Performance

Planning reads each selected relation with one query per chunk of parents (one per morph type for `MorphTo`), never one per parent. Each copy is one `INSERT` through Eloquent, so events and generated keys work. Plain pivot rows are inserted in batches. The identity map keeps every planned record in memory, so memory grows linearly with the graph (O(V + E)).

Measured on the package's test suite (an order with items, eight options per item and one reused tag per item; SQLite in memory, PHP 8.0, Laravel 6):

| Models | Selects | Inserts | Time | Peak memory |
| --- | --- | --- | --- | --- |
| 10 | 3 | 11 | 8 ms | 0.9 MB |
| 100 | 3 | 101 | 14 ms | 0.5 MB |
| 1,000 | 3 | 1,001 | 116 ms | 4.4 MB |
| 10,000 | 7 | 10,003 | 1,296 ms | 43.1 MB |

A network database adds one round trip per insert. Graphs much larger than these are better split into several operations.
