---
view: components.packages.features
variant: compact
badge: Key Features
title: Everything you need to duplicate real data
subtitle: Select the paths, call `replicate()`, and get the new root, an identity map and stats back.
items:
  - icon: grid
    title: Explicit copy, reuse and skip
    text: Every relation path gets a strategy. Nothing outside your selection is ever traversed.
  - icon: code-brackets
    title: All direct relations
    text: HasOne, HasMany, BelongsTo, BelongsToMany and every polymorphic relation, nested to any depth.
  - icon: lines
    title: Faithful pivots
    text: Every pivot row and column is recreated, custom `using()` pivot models and morph types included.
  - icon: database
    title: Identity map and cycles
    text: A record reached twice is copied once, and a cycle back to the root resolves to the new root.
  - icon: shield-check
    title: Atomic and protected
    text: One transaction, protected linking keys, no re-parenting, and a full rollback on any failure.
  - icon: eye
    title: Read-only dry run
    text: "`dryRun()` reports paths, projected counts and warnings without writing or firing events."
---
