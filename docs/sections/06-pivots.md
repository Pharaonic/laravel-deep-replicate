## Pivots

`BelongsToMany`, `MorphToMany` and `MorphedByMany` paths recreate one new pivot row per source pivot row. A `copy` path points the row to the related record's copy, and a `reuse` path points it to the original record.

### What is copied

- Every column of the pivot table, including columns not declared with `withPivot()`. Values are copied raw, so casts are not applied twice.
- Each source row separately: two rows for the same related record become two new rows. Rows are never collapsed with `sync()`.
- Not the surrogate key (`id`, or the custom pivot model's key), which the database or the pivot model generates again.
- New timestamps when the relation uses `withTimestamps()`. With `preserveTimestamps()`, the source pivot timestamps are kept.

Pivot constraints declared on the relation, such as `wherePivot()`, decide which rows are copied.

- ===Trait

  ```php
  // The new post is linked to the same tags; original tags and pivot rows are untouched.
  $post->deepReplicate(['tags' => 'reuse'])->replicate();
  ```

- ===Facade

  ```php
  // The new post is linked to the same tags; original tags and pivot rows are untouched.
  DeepReplicate::for($post)->reuse('tags')->replicate();
  ```

### Transforming pivot rows

`transformPivot()` receives the non-linking attributes, the source pivot model and the context, and returns the attributes to insert. `copyPivot()` only states the default, so you never need it.

- ===Trait

  ```php
  $item->deepReplicate(['tags' => 'reuse'])
      ->copyPivot('tags')
      ->transformPivot('tags', function (array $attributes, $sourcePivot, ReplicationContext $context): array {
          $attributes['primary'] = false;

          return $attributes;
      })
      ->replicate();
  ```

- ===Facade

  ```php
  DeepReplicate::for($item)
      ->reuse('tags')
      ->copyPivot('tags')
      ->transformPivot('tags', function (array $attributes, $sourcePivot, ReplicationContext $context): array {
          $attributes['primary'] = false;

          return $attributes;
      })
      ->replicate();
  ```

Setting a parent key, related key, morph type or surrogate key throws `ProtectedRelationshipAttributeException`.

### Custom pivot models

A relation that uses `->using(CustomPivot::class)` saves each new row through that pivot model, the way `attach()` does. Its casts and its model events run. Plain pivot tables are written with batched multi-row inserts, which fire no model events, as in Laravel itself. `RelationRule::withoutEvents()` on the pivot path silences custom pivot events.

### Constraints and limits

A unique pivot column that would be duplicated fails the insert and rolls back the whole replication. Change it with `transformPivot()`. `maxPivotRows()` (default 50,000) is checked while planning, before the first write.
