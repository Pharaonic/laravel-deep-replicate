## Relationships

### Support matrix

| Relation | `copy` | `reuse` | `skip` | Notes |
| --- | --- | --- | --- | --- |
| `HasOne` | Yes | Rejected | Yes | Reusing would move the child away from its original parent. |
| `HasMany` | Yes | Rejected | Yes | Children are never re-parented. |
| `BelongsTo` | Yes | Yes | Yes | A copied parent is inserted first and the copy's foreign key points to it. |
| `BelongsToMany` | Yes | Yes | Yes | Both strategies create new pivot rows for the new parent. |
| `MorphOne` | Yes | Rejected | Yes | The copy gets the new morph id and type. |
| `MorphMany` | Yes | Rejected | Yes | Same as MorphOne. |
| `MorphTo` | Yes | Yes | Yes | The concrete class comes from the stored type and the morph map. |
| `MorphToMany` | Yes | Yes | Yes | The pivot morph type and both keys are written. |
| `MorphedByMany` | Yes | Yes | Yes | The inverse side of MorphToMany. |
| `HasOneThrough`, `HasManyThrough` | Rejected | Rejected | Yes | Select the intermediate relations explicitly instead. |
| Custom `Relation` classes | Rejected | Rejected | Yes | Only the classes above are replicated, matched by exact class. |

Rejected combinations throw `UnsupportedReuseException` or `UnsupportedRelationshipException` before anything is written.

### Foreign keys you did not select

A copy keeps every raw attribute of its source except the primary key and the managed timestamps. Foreign keys of unselected or skipped relations keep their original values.

- ===Trait

  ```php
  $new = $post->deepReplicate('comments')->replicate()->model();

  $new->author_id === $post->author_id; // true, although "author" was not selected
  ```

- ===Facade

  ```php
  $new = DeepReplicate::for($post)->copy('comments')->replicate()->model();

  $new->author_id === $post->author_id; // true, although "author" was not selected
  ```

:::warning Retained keys can cross boundaries
A retained foreign key points to the original record. That is usually what you want (the same author, the same customer). It can also link the copy to data of another tenant or account. Select the relation as `copy`, or override the key with `with()`, when it must change.
:::

### Copy and reuse of a parent

- ===Trait

  ```php
  $order->deepReplicate('customer')->replicate();                // new customer, new customer_id
  $order->deepReplicate(['customer' => 'reuse'])->replicate();  // same customer_id
  ```

- ===Facade

  ```php
  DeepReplicate::for($order)->copy('customer')->replicate();  // new customer, new customer_id
  DeepReplicate::for($order)->reuse('customer')->replicate(); // same customer_id
  ```

A selected `BelongsTo` or `MorphTo` with a null key stays null and adds no record. When the key is set but the query does not return the record (it is missing, filtered, or hidden by a scope), the key is kept and the dry run reports a `missing_target` warning.

### Polymorphic relations

`MorphOne` and `MorphMany` copies receive the new parent's id and its morph class, which is the morph map alias when one is registered. `MorphTo` resolves each record's class from its stored type through `Relation::morphMap()`, and keeps the stored type exactly as it is. A nested path through `MorphTo` must exist on every class it reaches:

- ===Trait

  ```php
  // Activities of posts and videos: Video has no "comments" relation.
  $activity->deepReplicate('subject', 'subject.comments')->replicate();
  // InvalidRelationshipPathException: ... [subject.comments] ... Video
  ```

- ===Facade

  ```php
  // Activities of posts and videos: Video has no "comments" relation.
  DeepReplicate::for($activity)->copy('subject', 'subject.comments')->replicate();
  // InvalidRelationshipPathException: ... [subject.comments] ... Video
  ```

### What counts as a relation

A path segment must be a public, non-static method without required parameters, declared by your model, that returns an Eloquent relation. Framework and package methods such as `delete`, `save`, `replicate`, `restore` or `deepReplicate` are never called, and a method whose declared return type cannot be a relation is rejected without being called.
