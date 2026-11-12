# Maintaining This Package

This file records package-specific maintainer facts that are safe to keep in the public repository.

## Purpose

Replicates a chosen Eloquent relationship graph into new persisted records, with an explicit copy, reuse or skip strategy per relation path, in one transaction.

## Supported Laravel lines

One branch per Laravel major, starting with `6.x`. Each line's PHP range, `illuminate/*` constraint and tools follow the Pharaonic Laravel line table. `6.x` runs PHPUnit; later lines run Pest. Later lines are opened from `6.x`, in order.

## Core library

None. The package is Eloquent integration only and has no framework-independent logic to extract.

## Public API invariants

Configuration keys (`pharaonic.deep-replicate.*`), publish tags, and registered Blade, Artisan, macro, and validation names are public API.

- The package has no configuration, publishable resources, translations, migrations or routes.
- Public: `Concerns\DeepReplicates` (`deepReplicate()`, `deepReplicationDefaults()`), `Facades\DeepReplicate` (alias `DeepReplicate`), `DeepReplicator`, `ReplicationBuilder`, `RelationRule`, `ReplicationContext`, `ReplicationPlan`, `ReplicationResult`, `ReplicationMap`, `ReplicationStats`, `PlanningWarning` and every class in `Exceptions\`.
- Everything under `Engine\` and `Relations\`, and every method marked `@internal`, is internal.
- A deep replication always needs an explicit copy or reuse selection; model defaults never add paths or change strategies.
- The trait never defines `replicate()`.
- Builders are single-use; nothing is kept in static or singleton state between operations.
- `ReplicationMap` identity keys read `{connection}|{class}|{key type}:{key}`.

## Package-specific constraints

- All lines carry the same code, so it uses only APIs present in Laravel 6.20. Newer APIs are feature-detected through a plain `object` parameter (`Connection::afterCommit()`, `uniqueIds()` / `newUniqueId()`), which keeps PHPStan neutral across lines whose framework PHPDoc differs.
- `afterCommit` inside a caller's transaction needs `Connection::afterCommit()` (Laravel 8+); on 6.x and 7.x it throws `AfterCommitUnavailableException` before writing. That is the only documented behavior difference between lines.
- Relation handlers match relation classes exactly; subclasses are unsupported until a handler is added deliberately.
- A foreign key cycle is broken at its earliest-discovered node. Nullability is not inspected (Laravel 6 has no schema API for it), so a NOT NULL breaking column fails with `UnresolvableForeignKeyCycleException`.
- The fixtures analysed by PHPStan live in `tests/Fixtures/Analysed`; the other fixtures are not analysed.
- `tests/Performance` asserts query budgets; `DEEP_REPLICATE_BENCHMARK=10,100,1000,10000` prints the measured numbers.
- The `databases` workflow runs the suite on MySQL and PostgreSQL; `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` select the engine locally.

## Branch notes

`develop` is the integration branch. Fixes land on the oldest affected line and are ported to every later line and `develop`.

## Documentation

https://pharaonic.dev/packages/laravel/deep-replicate

Keep this file factual and package-specific. General Pharaonic engineering policy belongs in the private engineering repository, not here.
