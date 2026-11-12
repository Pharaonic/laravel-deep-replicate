## Troubleshooting

### UNIQUE constraint failed on a slug, SKU or email

The value was copied as is, because nothing is suffixed automatically. Give it a value with `generate()`, `with()` or `transform()` on the root, or with `configure()` on the path. The failed replication left no rows behind.

### MissingPrimaryKeyGeneratorException

The model has a non-incrementing key and no native unique id generator. Register one with `generate('<key>', ...)` on that path, or use `HasUuids` or `HasUlids` on Laravel 9.30 or later.

### ProtectedRelationshipAttributeException

A callback, `with`, `exclude` or `generate` changed a primary key or a key managed by a selected relation. Change the relation's strategy instead, or leave the key to the engine.

### UnsupportedReuseException on a HasMany

Reusing children would move them away from their parent. Copy them, or skip them.

### The copy has fewer children than expected

A global scope (soft deletes, tenant scopes) or a relation constraint hid them, or a `usingLoaded()` relation was loaded with a constraint. Check `dryRun()->estimatedModels()`, and use `withoutGlobalScopes()` only when crossing scopes is intended.

### The copy still points to the original parent

That relation was not selected, or it was skipped or filtered out, so its foreign key was kept. Select it as `copy`, or set the key with `with()`.

### AfterCommitUnavailableException

You registered `afterCommit` inside your own transaction on Laravel 6 or 7. Replicate outside the transaction, or dispatch the work yourself after your commit.

### UnresolvableForeignKeyCycleException

The selection forms a foreign key cycle and the column used to break it is `NOT NULL`. Make that column nullable, start from the other model, or do not select both directions.

### afterCommit never runs in my tests

`RefreshDatabase` wraps each test in a transaction that never commits. Use `DatabaseMigrations` or `DatabaseTruncation` for tests that depend on `afterCommit`.
