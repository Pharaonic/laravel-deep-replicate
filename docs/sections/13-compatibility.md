## Compatibility

Every Laravel major has its own release line with the same package API. Versions are `N.minor.patch`, where `N` is the Laravel major.

| Line | PHP | Laravel | Status |
| --- | --- | --- | --- |
| 6.x | 8.0 | 6.20+ | Tested with lowest and highest dependencies on PHP 8.0. |

Further lines (7.x to 13.x) are opened one at a time from this one, each tested on every PHP version its Laravel major supports, up to PHP 8.5.

:::warning End-of-life frameworks
Laravel 6 no longer receives security fixes. This line is maintained for applications that still run it, but the framework release, and its advisories, are your application's choice.
:::

### Version-specific behavior

| Feature | Laravel 6 and 7 | Laravel 8 and later |
| --- | --- | --- |
| `afterCommit` inside your own transaction | Throws `AfterCommitUnavailableException` before writing. | Waits for your outermost commit. |
| Native UUID and ULID keys | Laravel 6 has no `HasUuids`; register a generator or define `uniqueIds()` and `newUniqueId()` yourself. | `HasUuids` and `HasUlids` (9.30+) are used automatically. |

The databases tested are SQLite with foreign keys enforced and, in CI, MySQL and PostgreSQL.
