---
view: components.home.faq
badge: FAQ
title: "{package.name}"
highlight: Questions
subtitle: "Quick answers about installing and using {package.name}."
---

## What is {package.name}?

{card.description} It's a free, open-source {technology.name} package by Pharaonic.

## How do I install {package.name}?

Run `composer require {package.composer}` in your project's root directory.

## What does {package.name} require?

The latest release requires {package.requiresText}.

## How is it different from Eloquent's replicate()?

`replicate()` returns one unsaved copy of one model. {package.name} copies the relations you select, links the copies to each other, recreates pivot rows and saves everything in one transaction.

## Can I copy a model without any relation?

No. A deep replication always needs at least one relation selected as copy or reuse. Use Laravel's `replicate()` for the model alone.

## What happens to unique columns like slugs?

They are never changed automatically. Give them a value with `generate()`, `with()` or `transform()`; otherwise the database rejects the duplicate and nothing is kept.

## Is {package.name} free to use?

Yes. {package.name} is open source under the {package.license} license, so you can use it in personal and commercial projects.

## Where can I find the {package.name} documentation?

Read the [{package.name} documentation]({package.docsUrl}) for setup, configuration, and usage examples.

## How do I report a bug or contribute to {package.name}?

Open an issue or a pull request on [GitHub]({package.githubUrl}), ask in [GitHub Discussions](https://github.com/orgs/Pharaonic/discussions), or chat with us on [Discord](https://discord.gg/XQG9RhvEvf).
