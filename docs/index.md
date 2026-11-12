---
name: Deep Replicate

action:
  label: View on Packagist
  href: "{package.packagistUrl}"

views: components.packages

breadcrumbs:
  - label: Home
    href: route:home
  - label: Packages
    href: route:packages.index
  - label: "{technology.name} Packages"
    href: "url:/packages/{technology.slug}"
  - label: "{package.name}"

card:
  topic: database
  icon: database
  tags: eloquent replicate clone duplicate copy deep relationships graph pivot
  description: Replicate a chosen Eloquent relationship graph into new persisted records, with explicit copy, reuse and skip rules per relation path, in one transaction.

seo:
  title: "{package.fullName} - Deep Eloquent Model Replication"
  description: "{package.name} is a Laravel package that copies a selected Eloquent relationship graph, pivots included, in one transaction. {package.downloadsShort}+ downloads, {package.license} licensed."
  keywords: laravel deep replicate, eloquent replicate relationships, clone model with relations, duplicate eloquent model, copy pivot rows
  author: Pharaonic
  images:
    - "{package.cover}"
  openGraph:
    type: website
    siteName: Pharaonic
  twitter:
    card: summary_large_image

schema:
  "@type": SoftwareSourceCode
  name: "{package.name}"
  description: "{package.name} is a Laravel package that replicates a chosen Eloquent relationship graph into new persisted records."
  image: "{package.cover}"
  codeRepository: "{package.githubUrl}"
  programmingLanguage: PHP
  runtimePlatform: "{technology.name}"
  version: "{package.version}"
  datePublished: "{package.publishedAt}"
  dateModified: "{package.updatedAt}"
  license: "https://opensource.org/licenses/{package.license}"
  isAccessibleForFree: true
  sameAs:
    - "{package.githubUrl}"
    - "{package.packagistUrl}"
  author:
    "@id": url:/#organization
  publisher:
    "@id": url:/#organization
  interactionStatistic:
    "@type": InteractionCounter
    interactionType: https://schema.org/DownloadAction
    userInteractionCount: "{package.downloads}"
---
