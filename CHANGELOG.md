# Changelog

## 0.10.0

New storage format and BC breaks — see [UPGRADE.md](UPGRADE.md).

### Added
- Support for nette/caching 4.
- All `Cache` dependencies: `Files`, `Constants`, `Callbacks`, `Sliding`, `Items`; `false` can be cached.
- Garbage collection: `$storage->clean([])` (cron) plus a small step on random writes.
- Option `defaultTtl` (default 3600, `null` = until invalidated).
- `Redis::OPT_COMPRESSION` support.
- Storage is registered even without Nette's `CacheExtension`.

### Fixed
- Cleaning a namespace did not invalidate derived caches, and the first clean of a namespace did nothing.
- Stale data after tag/priority clean when items were written concurrently (journal changes are now atomic).
- `Cache::All` ran `FLUSHDB` and deleted sessions and other data in the database.
- Tags/priority without a journal were silently ignored.
- Journal grew forever (entries of expired items were never removed).
- Failed `SELECT`, `AUTH` or Redis errors during writes were ignored.
- Persistent connections of different databases could share one socket.
- Session `save_path` lost `database`/`prefix` with a non-default `arg_separator.output`.
- `timeout: 2` (integer) was rejected in the config.
- Integer tags, negative and float priorities.

### BC breaks
- New storage format; 0.9 and 0.10 cannot share a Redis.
- Tags/priority without a journal throw `Nette\InvalidStateException`.
- `RedisFactory` throws on failed `AUTH`/`SELECT`.
- Config: `prefix` must not be empty, databases must be ≥ 0.
- `RedisStorage` requires the PHP or igbinary serializer, no `OPT_PREFIX`, and a `RedisJournal` on the same connection.
- `RedisKeyBuilder::build()` signature changed.
- Requires `nette/caching` 3.3.1+ or 4.x and `nette/di` 3.2.3+.

### Other
- CI: PHP 8.4/8.5/8.6 × nette/caching 3/4, lowest dependencies, both serializers.
- `vendor/` and `composer.lock` removed from the repository.
