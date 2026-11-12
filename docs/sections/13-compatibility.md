## Compatibility

Every Laravel major has its own release line with the same package API. Versions are `N.minor.patch`, where `N` is the Laravel major.

| Line | PHP | Laravel | Status |
| --- | --- | --- | --- |
| 6.x | 8.0 | 6.20+ | Tested with lowest and highest dependencies on PHP 8.0. |
| 7.x | 8.0 | 7.30+ | Tested with lowest and highest dependencies on PHP 8.0. |
| 8.x | 8.0, 8.1 | 8.75+ | Tested with lowest and highest dependencies on PHP 8.0 and 8.1. |
| 9.x | 8.0 to 8.2 | 9.33+ | Tested with lowest and highest dependencies on PHP 8.0, 8.1 and 8.2. |
| 10.x | 8.1 to 8.3 | 10.0+ | Tested with lowest and highest dependencies on PHP 8.1, 8.2 and 8.3. |
| 11.x | 8.2 to 8.4 | 11.0+ | Tested with lowest and highest dependencies on PHP 8.2, 8.3 and 8.4. |
| 12.x | 8.2 to 8.5 | 12.0+ | Tested with lowest and highest dependencies on PHP 8.2 to 8.5. |

The 13.x line is opened next, tested on every PHP version Laravel 13 supports.

### Version-specific behavior

| Feature | Laravel 6 and 7 | Laravel 8 and later |
| --- | --- | --- |
| `afterCommit` inside your own transaction | Throws `AfterCommitUnavailableException` before writing. | Waits for your outermost commit. |
| Native UUID and ULID keys | Laravel 6 has no `HasUuids`; register a generator or define `uniqueIds()` and `newUniqueId()` yourself. | `HasUuids` and `HasUlids` (9.30+) are used automatically. |

The databases tested are SQLite with foreign keys enforced and, in CI, MySQL and PostgreSQL.
