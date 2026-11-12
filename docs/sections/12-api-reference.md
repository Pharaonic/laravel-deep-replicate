## API Reference

### Entry points

| Method | Returns |
| --- | --- |
| `$model->deepReplicate(string\|array $relations, string ...$paths)` | `ReplicationBuilder` (trait `Concerns\DeepReplicates`) |
| `DeepReplicate::for(Model $source, array\|string\|null $relations = null)` | `ReplicationBuilder` (facade) |
| `DeepReplicator::for(Model $source, array\|string\|null $relations = null)` | `ReplicationBuilder` (container service) |

### ReplicationBuilder

| Method | Description |
| --- | --- |
| `relations(array $rules)` | Select paths: list values are copied, keys take `copy`, `reuse` or `skip`. |
| `copy(string $path, string ...$paths)` | Copy these paths. |
| `reuse(string $path, string ...$paths)` | Reuse these paths. |
| `skip(string $path, string ...$paths)` | Skip these paths. |
| `exclude(array $attributes)` | Leave attributes out of the root copy. |
| `with(array $attributes)` | Set attributes on the root copy through mutators and casts. |
| `transform(callable $callback)` | Transform the root copy's raw attributes. |
| `generate(string $attribute, callable $callback)` | Generate one root attribute. |
| `configure(string $path, callable $callback)` | Configure a copied path through a `RelationRule`. |
| `filter(string $path, callable $callback)` | Constrain the query of a selected path. |
| `usingLoaded(string $path, string ...$paths)` | Use the loaded relations of these paths. |
| `copyPivot(string $path, string ...$paths)` | State that pivot attributes are copied (the default). |
| `transformPivot(string $path, callable $callback)` | Transform the non-linking attributes of new pivot rows. |
| `preserveTimestamps()` | Keep source timestamps on copies and pivot rows. |
| `preserveSoftDelete()` | Keep source `deleted_at` values. |
| `withoutGlobalScopes()` | Remove every global scope from relation queries. |
| `withoutEvents()` | Suppress model events during the operation. |
| `maxDepth(int)`, `maxModels(int)`, `maxPivotRows(int)`, `chunk(int)` | Limits. |
| `beforeReplicate`, `beforeModel`, `afterModel`, `afterReplicate`, `afterCommit` | Lifecycle callbacks. |
| `dryRun(): ReplicationPlan` | Read-only plan. |
| `replicate(): ReplicationResult` | Run the replication. |

### RelationRule

| Method | Description |
| --- | --- |
| `exclude(array $attributes)` | Leave attributes out of the path's copies. |
| `with(array $attributes)` | Set attributes through mutators and casts. |
| `transform(callable $callback)` | Transform raw attributes; several run in order. |
| `generate(string $attribute, callable $callback)` | Generate one attribute per copied record. |
| `withoutEvents()` | Suppress model events for this path's copies. |
| `preserveTimestamps(bool $preserve = true)` | Override the builder's setting for this path. |

### Exceptions

Every exception extends `Exceptions\DeepReplicationException`, which extends `RuntimeException`.

| Exception | Thrown when |
| --- | --- |
| `EmptyRelationshipSelectionException` | No path is selected as copy or reuse. |
| `InvalidRelationshipPathException` | A path is malformed, undefined, not a relation, not selected for an option, or reaches an unknown morph type. |
| `InvalidRelationshipStrategyException` | A strategy is not copy, reuse or skip. |
| `ConflictingRelationshipRuleException` | One path has two strategies. |
| `IncompatibleRelationOptionsException` | An active path is under a skipped or reused one, an option targets an incompatible strategy, `filter()` meets `usingLoaded()`, or a pivot option targets a non-pivot relation. |
| `UnsupportedRelationshipException` | A through or custom relation is copied or reused. |
| `UnsupportedReuseException` | A HasOne, HasMany, MorphOne or MorphMany path is reused. |
| `MissingLoadedRelationException` | A `usingLoaded()` relation is not loaded on a source parent. |
| `UnsavedSourceModelException` | A model of the graph is not persisted or has no key. |
| `CrossConnectionReplicationException` | A model uses another connection. |
| `ConflictingIdentityStrategyException` | One record is both copied and reused. |
| `ConflictingNodeConfigurationException` | One copied record has two configurations or two values for a linking key. |
| `UnresolvableForeignKeyCycleException` | The database rejects the NULL used to break a foreign key cycle. |
| `ProtectedRelationshipAttributeException` | A primary or linking key, or a pivot linking column, is changed. |
| `MissingPrimaryKeyGeneratorException` | A non-incrementing key has no generator. |
| `ReplicationLimitExceededException` | A depth, model or pivot limit is exceeded. |
| `AfterCommitUnavailableException` | `afterCommit` cannot wait for the caller's outermost commit on this Laravel version. |
| `BuilderAlreadyExecutedException` | A builder runs a second time. |
| `ReplicationCancelledException` | A model event listener returned false and cancelled an insert. |
