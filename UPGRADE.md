# Upgrading from 0.9 to 0.10

0.10 stores data in a new format. The cache starts empty after the upgrade; sessions are not affected.

## Before deploying

- [ ] Redis 4+, standalone (no Cluster), Lua scripting allowed (`EVAL`/`EVALSHA`).
- [ ] With the journal, Redis `maxmemory-policy` is `volatile-*` (recommended) or `noeviction` (a full Redis then makes
  cache writes throw) — not `allkeys-*`.
- [ ] `composer require neatous/redis:^0.10` (also needs `nette/caching` 3.3.1+ or 4.x, `nette/di` 3.2.3+).
- [ ] Config: `prefix` is not empty; `database`, `cacheDatabase`, `sessionDatabase` exist on the server.
- [ ] Code using `Cache::Tags` / `Cache::Priority` does not run with `journal: false`.
- [ ] Code that creates `RedisStorage`/`RedisJournal`/`RedisKeyBuilder` directly: see below.

## Deploy

1. Switch all servers at once — 0.9 and 0.10 must not run against the same Redis at the same time.
2. Once, after deploying (required — removes 0.9 data that is never cleaned otherwise; keeps sessions):
   ```php
   $container->getByType(Nette\Caching\Storage::class)->clean([Nette\Caching\Cache::All => true]);
   ```
3. Schedule the garbage collector, e.g. hourly:
   ```php
   $container->getByType(Nette\Caching\Storage::class)->clean([]);
   ```

## What can now throw (0.9 failed silently)

- Tags or priority without a journal → `Nette\InvalidStateException`.
- Wrong password, missing database, or a Redis error during a cache operation (e.g. OOM on write) → `RedisException`.
- Invalid config (`prefix: ''`, negative database, `defaultTtl` < 1) → config validation error.

## Behavior changes

- Cache is cold right after the deploy — expect higher load.
- `Cache::All` deletes only this library's cache and journal keys (0.9 flushed the whole database).
- Items without `Cache::Expire` live `defaultTtl` (3600 s, as before); `defaultTtl: null` keeps them until invalidated.
- nette/caching 4: `$cache->clean(all: true)` on a namespaced cache cleans only that namespace and caches derived from it.
- With `persistent: true`, sessions use a persistent connection too; sessions get the `timeout` setting.

## Using the classes directly

- `RedisStorage` needs a connection from `RedisFactory` (PHP or igbinary serializer, no `OPT_PREFIX`), a `RedisJournal`
  on the **same** connection, and `defaultTtl` ≥ 1 or null.
- `RedisKeyBuilder::build()` takes `list<string> $versions` instead of `int $version`.
- `RedisKeyBuilder` and `RedisJournal` reject an empty prefix.
- `RedisSessionConfigurator::configure()` throws when the session has already started.
