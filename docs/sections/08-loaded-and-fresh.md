## Loaded and Fresh Relations

### Fresh queries by default

Selected relations are queried again, following their definition: its constraints, the global scopes, and the foreign key and morph constraints. A relation already loaded on the source is ignored, so a constrained eager load never shrinks the copy by accident.

- ===Trait

  ```php
  $post->load(['comments' => fn ($q) => $q->where('approved', true)]);

  $post->deepReplicate('comments')->replicate();                          // every comment
  $post->deepReplicate('comments')->usingLoaded('comments')->replicate(); // the approved ones
  ```

- ===Facade

  ```php
  $post->load(['comments' => fn ($q) => $q->where('approved', true)]);

  DeepReplicate::for($post)->copy('comments')->replicate();                          // every comment
  DeepReplicate::for($post)->copy('comments')->usingLoaded('comments')->replicate(); // the approved ones
  ```

### usingLoaded()

`usingLoaded($path)` uses exactly the loaded collection or model on every source parent of that path, and throws `MissingLoadedRelationException` when one is missing. It never loads anything. It does not apply to descendant paths: list each one, and load it on every parent.

- ===Trait

  ```php
  $order->load('items.options');

  $order->deepReplicate('items.options')->usingLoaded('items', 'items.options')->replicate();
  ```

- ===Facade

  ```php
  $order->load('items.options');

  DeepReplicate::for($order)->copy('items.options')->usingLoaded('items', 'items.options')->replicate();
  ```

A loaded pivot carries only the columns that were loaded. The dry run warns (`unloaded_pivot_columns`) when the table has more; load them with `withPivot()`.

### filter()

`filter($path, $callback)` constrains the query of one selected path. The callback receives the relation, as an eager-load constraint does. Several filters on a path all apply. The source model and its relations are never modified.

- ===Trait

  ```php
  $order->deepReplicate('items.options')
      ->filter('items', fn ($query) => $query->where('active', true))
      ->replicate();
  ```

- ===Facade

  ```php
  DeepReplicate::for($order)
      ->copy('items.options')
      ->filter('items', fn ($query) => $query->where('active', true))
      ->replicate();
  ```

`filter()` and `usingLoaded()` on the same path throw `IncompatibleRelationOptionsException`. A filter on a `BelongsTo` or `MorphTo` path that excludes the parent keeps the original foreign key.

### Global scopes and soft deletes

Global scopes apply to every relation query. Soft-deleted children are therefore not copied unless you call `withoutGlobalScopes()`, which removes every global scope from the relation queries:

- ===Trait

  ```php
  $order->deepReplicate('items')->withoutGlobalScopes()->preserveSoftDelete()->replicate();
  ```

- ===Facade

  ```php
  DeepReplicate::for($order)->copy('items')->withoutGlobalScopes()->preserveSoftDelete()->replicate();
  ```

:::danger withoutGlobalScopes() is privileged
It also removes tenant, team and authorization scopes, so the copy can include records the current user must not see. Use it only when crossing those scopes is intended. The dry run reports a `global_scopes_removed` warning.
:::
