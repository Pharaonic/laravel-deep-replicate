## Nested Graphs

### Paths and precedence

A nested path implies `copy` of its unselected parents: `copy('items.options')` also copies `items`. A skipped nested path implies nothing.

| Selection | Result |
| --- | --- |
| `copy('items')`, `reuse('items.product')` | Items are copied; their products are reused. |
| `copy('items.options')` | Items and options are copied. |
| `skip('items')`, `copy('items.options')` | `IncompatibleRelationOptionsException`: a skipped relation is not traversed. |
| `reuse('items')`, `copy('items.options')` | `IncompatibleRelationOptionsException`: reuse is a terminal. |
| `copy('items')`, `copy('items')` | Same as one call. |
| `copy('items')`, `reuse('items')` | `ConflictingRelationshipRuleException`, whatever the order. |

The whole selection is normalized and validated before any query runs.

### One copy per original record

Every original record gets at most one copy per operation. A record reached through two copy paths is copied once and linked from both.

- ===Trait

  ```php
  // "items" and "activeItems" both reach the active item: it is copied once.
  $order->deepReplicate('items', 'activeItems')->replicate();
  ```

- ===Facade

  ```php
  // "items" and "activeItems" both reach the active item: it is copied once.
  DeepReplicate::for($order)->copy('items', 'activeItems')->replicate();
  ```

Identity is the resolved connection, the model class, and the primary key with its type. Integer `1` and string `"1"` are different records.

A record that one path copies and another reuses throws `ConflictingIdentityStrategyException`. A record copied through two paths whose rules configure it differently throws `ConflictingNodeConfigurationException`. A rule set on only one of the paths applies to the shared copy.

### Cycles

A selected path that leads back to the root resolves to the new root, so no second root is created:

- ===Trait

  ```php
  $result = $post->deepReplicate('author', 'author.posts')
      ->configure('author', fn ($rule) => $rule->with(['email' => 'copy@example.com']))
      ->replicate();

  // The new author owns the new post and copies of the author's other posts.
  ```

- ===Facade

  ```php
  $result = DeepReplicate::for($post)
      ->copy('author', 'author.posts')
      ->configure('author', fn ($rule) => $rule->with(['email' => 'copy@example.com']))
      ->replicate();

  // The new author owns the new post and copies of the author's other posts.
  ```

### Insert order and foreign key cycles

Copies are inserted after the copies their foreign keys point to: a copied `BelongsTo` parent goes first, and a copied child goes after its parent. When the selection forms a foreign key cycle (a user's `current_team_id` points to a team whose `owner_id` points back to the user), the cycle is broken at its earliest-reached model. That model is inserted with the cycle column set to `NULL`, and the column is updated once its target exists.

When the database rejects that `NULL` value, the replication fails with `UnresolvableForeignKeyCycleException` and nothing is kept. Foreign key checks are never disabled.

:::warning Which column is nulled
The cycle is always broken at the model reached first, which is the root when the cycle includes it. If that column is `NOT NULL` while another column of the cycle is nullable, the operation still fails. Make the root's column nullable, or start the replication from the other model.
:::
