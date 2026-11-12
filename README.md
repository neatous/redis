<h1 align="center">
	<img src="docs/img/neatous.png" alt="Logo" height="120" />
	<br/>
	neatous/redis
</h1>

Redis cache storage and sessions for Nette, using phpredis.

## Requirements

- PHP 8.4+, ext-redis 6.0+ (ext-igbinary optional, used when available)
- `nette/caching` 3.3.1+ or 4.x, `nette/di` 3.2.3+
- Redis 4+, standalone (no Cluster), Lua scripting allowed

Upgrading from 0.9? See [UPGRADE.md](UPGRADE.md).

## Installation

```bash
composer require neatous/redis
```

```neon
extensions:
    redis: Netous\Redis\DI\RedisExtension

redis:
    cache: true
    sessions: true
```

## Options

| Option | Default | Description |
|---|---|---|
| `host` | `127.0.0.1` | Redis host |
| `port` | `6379` | Redis port |
| `password` | `null` | Redis password |
| `database` | `0` | Database index |
| `cacheDatabase` | `null` | Database for the cache (default `database`) |
| `sessionDatabase` | `null` | Database for sessions (default `database`) |
| `timeout` | `1.5` | Connection timeout in seconds |
| `persistent` | `false` | Persistent connections |
| `prefix` | `app:` | Key prefix — unique per application, not nested in another application's prefix |
| `cache` | `false` | Use Redis as the Nette cache storage |
| `journal` | `true` | Enable `Cache::Tags` and `Cache::Priority` |
| `defaultTtl` | `3600` | Lifetime of items without `Cache::Expire`; `null` = until invalidated |
| `sessions` | `false` | Store PHP sessions in Redis |
| `debug` | `false` | Tracy panel (requires `tracy/tracy`) |

## Cache

All `Nette\Caching\Cache` instances use Redis. All dependencies are supported (`Expire`, `Sliding`, `Tags`,
`Priority`, `Files`, `Constants`, `Callbacks`, `Items`; `Sliding` needs `Expire`).

Run the garbage collector regularly, e.g. hourly from cron:

```php
$container->getByType(Nette\Caching\Storage::class)->clean([]);
```

Without the extension:

```php
$redis = (new Netous\Redis\Client\RedisFactory())->create(new Netous\Redis\Client\RedisConfig(prefix: 'myapp:'));
$storage = new Netous\Redis\Cache\RedisStorage(
    $redis,
    new Netous\Redis\Cache\RedisKeyBuilder('myapp:'),
    new Netous\Redis\Cache\RedisJournal($redis, 'myapp:'), // same connection
);
```

## Notes

- `Cache::All` deletes only this library's keys (`{prefix}cache…`, `{prefix}journal:`); sessions and other data stay.
- With the journal, set `maxmemory-policy` to `volatile-*` (recommended) or `noeviction` (a full Redis then makes cache
  writes throw); `allkeys-*` may evict journal keys and break tag invalidation.
- Anyone who can write to your Redis can run code in your application (cached data is unserialized, `Cache::Callbacks`
  are executed). Protect Redis with a password/ACL and keep it off untrusted networks.
- Sessions: the Redis password is part of `session.save_path`, which `phpinfo()` and Tracy's error page show. Restrict
  access to Tracy logs or set `Tracy\Debugger::getBlueScreen()->showEnvironment = false`.
- A custom connection must use the PHP or igbinary serializer and no `Redis::OPT_PREFIX`; compression is supported.

## Testing

```bash
composer install
vendor/bin/phpunit
REDIS_TEST_SERIALIZER=php vendor/bin/phpunit
```

Requires Redis on `127.0.0.1:6379`. **The tests flush databases 14 and 15.**

## License

MIT
