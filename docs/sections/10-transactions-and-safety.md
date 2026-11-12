## Transactions and Safety

### One transaction

`replicate()` runs in one transaction on the source model's connection. It plans the graph again inside that transaction, so a dry run's snapshot is never reused. Then it inserts, links, attaches pivots, runs the callbacks and commits. Any exception rolls back every row the operation wrote and is rethrown, with database errors kept as the original `QueryException`. Inside your own transaction the operation uses a savepoint, and your rollback also discards the replica.

The transaction is attempted once. A deadlock is not retried, because callbacks are not idempotent. Retry the whole call yourself if your application needs it.

### One connection

Every model of the graph must use the source's connection. A related model on another connection throws `CrossConnectionReplicationException` while planning, before any write. Connections are compared as resolved connection objects, so a model that names the default connection explicitly is not "another connection".

### Concurrency

The plan is read inside the transaction, so it reflects the transaction's isolation level, not a global snapshot. Under the default READ COMMITTED of MySQL or PostgreSQL, rows committed by others between two planning queries may be seen. Lock the source records (for example with `lockForUpdate()` in your own transaction) when concurrent writes must not change the graph.

### Security notes

- Relation paths are resolved through Eloquent metadata only; no SQL is built from path strings.
- Exception messages name classes, paths and columns, never attribute values.
- Global scopes and foreign key checks are never disabled implicitly.
- Files, media, cloud objects and external resources are not copied, even when an attribute holds their path. Copy them yourself in `afterCommit`.
- Builders are single-use objects with no shared state, so they are safe to create per request.
