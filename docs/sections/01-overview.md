:::badges
- Laravel Package {color=blue}
- {release.label} {color=green}
- {package.license} License {color=purple}
:::

# Deep Replicate

Laravel's `Model::replicate()` makes one unsaved copy of one model. Deep Replicate copies a relationship graph that you choose into new, persisted records, in a single transaction. For each relation path you decide whether its records are copied, reused, or skipped.

:::features
### Explicit graphs {icon="grid"}
Nothing is traversed unless you select it. Every path is copied, reused or skipped on purpose.

### Every direct relation {icon="code-brackets"}
HasOne, HasMany, BelongsTo, BelongsToMany, MorphOne, MorphMany, MorphTo, MorphToMany and MorphedByMany.

### Safe by design {icon="shield-check"}
Reused records are never re-parented, linking keys are protected, and a failure rolls everything back.

### One copy per record {icon="database"}
An identity map copies a record reached through several paths once, and resolves cycles back to the new root.

### Pivots kept intact {icon="lines"}
Every pivot row is recreated with all its columns, custom pivot models and polymorphic types included.

### Inspect first {icon="eye"}
A read-only dry run reports the graph, counts and warnings without writing or firing events.
:::

:::info Quick Tip
Start with `dryRun()` on a real record. It shows exactly which paths, models and pivot rows a replication would create.
:::
