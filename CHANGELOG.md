# Changelog

All notable changes to this project are documented here.

## 8.0.0 - 2026-11-12

### Compatibility

- Laravel 8.75+ on PHP 8.0 and 8.1.
- The same API as 7.0.0. `afterCommit` inside a caller's transaction now waits for the outermost commit through `Connection::afterCommit()`, instead of throwing `AfterCommitUnavailableException`.

## 7.0.0 - 2026-11-12

### Compatibility

- Laravel 7.30+ on PHP 8.0.
- The same API and behavior as 6.0.0, including `AfterCommitUnavailableException` for `afterCommit` inside a caller's transaction (Laravel 8 adds the native support).
- The test suite runs on Pest.

## 6.0.0 - 2026-11-12

### Added

- `ReplicationBuilder`, reached through the `DeepReplicates` model trait (`deepReplicate()`), the `DeepReplicate` facade (`DeepReplicate::for()`) and the `DeepReplicator` service.
- `copy`, `reuse` and `skip` strategies per relation path, as positional paths, `relations()` arrays or fluent methods, with nested dot paths and implied parent copies.
- Replication of HasOne, HasMany, BelongsTo, BelongsToMany, MorphOne, MorphMany, MorphTo, MorphToMany and MorphedByMany relations. Through relations and custom relation classes are rejected unless skipped.
- One copy per original record across all paths (identity map), cycles resolved to the new root, dependency-ordered inserts, and NULL-then-update links for nullable foreign key cycles.
- Pivot rows recreated one per source row, with every pivot column, custom `using()` pivot models, morph types, new or preserved timestamps, and `transformPivot()`.
- Root and per-path attribute rules: `exclude()`, `with()`, `generate()`, `transform()`, `configure()` with `RelationRule`, and `deepReplicationDefaults()` fallbacks for selected paths.
- Protected primary and linking keys, native unique id regeneration (`uniqueIds()` / `newUniqueId()`), `preserveTimestamps()` and `preserveSoftDelete()`.
- Fresh relation queries by default, `usingLoaded()`, `filter()` and `withoutGlobalScopes()`.
- Lifecycle callbacks `beforeReplicate`, `beforeModel`, `afterModel`, `afterReplicate` and `afterCommit`, Eloquent events on by default, and global or per-path `withoutEvents()`.
- Single-connection transactional execution, read-only `dryRun()` returning a `ReplicationPlan`, and `ReplicationResult` with `model()`, `map()` and `stats()`.
- `maxDepth()`, `maxModels()`, `maxPivotRows()` and `chunk()` limits, enforced before the first write.
- A `DeepReplicationException` hierarchy with one exception per failure.
- PHPUnit suite on SQLite with foreign keys enforced, configurable for MySQL and PostgreSQL, with query-budget performance tests.
- Package documentation in `docs/` for pharaonic.dev.
