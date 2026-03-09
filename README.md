# netous/redis

Minimalist Redis integration for Nette Framework — cache storage and session handler using the phpredis extension. Designed for zero overhead with O(1) namespace invalidation.

## Requirements

- PHP 8.4+
- ext-redis >= 6.0
- Nette Framework 3.x

Optional: `ext-igbinary` for faster serialization (auto-detected).

## Installation

```bash
composer require netous/redis
```

## Configuration

Register the extension in your NEON config:

```neon
extensions:
    redis: Netous\Redis\DI\RedisExtension

redis:
    host: 127.0.0.1
    cache: true
    sessions: true
```

All options are optional — defaults are shown below.

### Options

| Option | Type | Default | Description |
|---|---|---|---|
| `host` | string | `127.0.0.1` | Redis server hostname |
| `port` | int | `6379` | Redis server port |
| `password` | string\|null | `null` | Authentication password |
| `database` | int | `0` | Redis database index |
| `timeout` | float | `1.5` | Connection timeout in seconds |
| `persistent` | bool | `false` | Use persistent connections |
| `prefix` | string | `"app:"` | Key prefix for all Redis keys |
| `cache` | bool | `false` | Register as Nette cache storage |
| `journal` | bool | `true` | Register `RedisJournal` for tag/priority invalidation (only applies when `cache: true`) |
| `sessions` | bool | `false` | Configure PHP sessions to use Redis |
| `cacheDatabase` | int\|null | `null` | Redis database index for cache (falls back to `database`) |
| `sessionDatabase` | int\|null | `null` | Redis database index for sessions (falls back to `database`) |
| `debug` | bool | `false` | Show Tracy debug panel |

### Separate databases

To prevent a cache flush from destroying active user sessions, configure separate Redis databases:

```neon
redis:
    database: 0
    cacheDatabase: 0
    sessionDatabase: 1
    cache: true
    sessions: true
```

## Usage

### Cache storage

Enable `cache: true` in your config. The extension registers `RedisStorage` as the default `Nette\Caching\Storage` implementation, so all `Nette\Caching\Cache` instances will use Redis automatically.

```php
use Nette\Caching\Cache;

class ProductService
{
    public function __construct(
        private Cache $cache,
    ) {
    }

    public function getProduct(int $id): Product
    {
        return $this->cache->load("product-$id", function () use ($id) {
            return $this->repository->find($id);
        });
    }
}
```

### Sessions

Enable `sessions: true` in your config. PHP sessions will be stored in Redis using the native phpredis session handler. No additional code changes are needed.

### Standalone usage

You can use the Redis client and cache storage without the Nette DI extension:

```php
use Netous\Redis\Client\RedisConfig;
use Netous\Redis\Client\RedisFactory;
use Netous\Redis\Cache\RedisKeyBuilder;
use Netous\Redis\Cache\RedisStorage;

$config = new RedisConfig(
    host: '10.0.0.1',
    password: 'secret',
    database: 2,
    prefix: 'myapp:',
);

$redis = (new RedisFactory())->create($config);
$keyBuilder = new RedisKeyBuilder($config->prefix);
$journal = new \Netous\Redis\Cache\RedisJournal($redis, $config->prefix);
$storage = new RedisStorage($redis, $keyBuilder, $journal);
```

## Tracy panel

When enabled, a Redis panel is added to the Tracy debug bar showing connection status, ping latency, key count, and memory usage for each registered connection (client, cache, session).

```neon
redis:
    debug: true
```

Requires `tracy/tracy`:

```bash
composer require tracy/tracy
```

## How it works

### Key format

Cache keys are stored as:

```
{prefix}cache:{namespace}:{version}:{sha1(key)}
```

The SHA-1 hash ensures safe, fixed-length keys regardless of the original key content.

### Tag and priority invalidation (Journal)

When `cache: true` is enabled, the extension also registers `RedisJournal` as the `Nette\Caching\Storages\Journal` implementation by default. This enables cache invalidation by tags and priorities:

```php
// Writing with tags and priority
$cache->save('product-42', $data, [
    Cache::Tags => ['product', 'category-5'],
    Cache::Priority => 50,
]);

// Invalidate all entries tagged with "product"
$cache->clean([Cache::Tags => ['product']]);

// Invalidate all entries with priority <= 30
$cache->clean([Cache::Priority => 30]);
```

The journal stores metadata in Redis using SETs (for tag→key and key→tag mappings) and a SORTED SET (for priorities). When a cache entry is rewritten, its previous journal entries are automatically cleaned up.

If you don't use tags or priorities, you can disable the journal to avoid the overhead:

```neon
redis:
    cache: true
    journal: false
```

### Namespace invalidation

Namespaces are invalidated in O(1) time using a version counter. Each namespace has a version key:

```
{prefix}cache:ns:{namespace}
```

When a namespace is cleaned, the version is incremented with `INCR`. All existing keys for the previous version become orphaned and expire naturally via their TTL. No `KEYS` or `SCAN` commands are used.

### Redis commands used

The implementation uses a minimal set of Redis commands:

- `GET` — read cache entries and namespace versions
- `SETEX` — write cache entries with TTL
- `DEL` — remove individual cache entries
- `INCR` — increment namespace version for O(1) invalidation

### Serialization

If the `igbinary` extension is available, it is used automatically for Redis value serialization. Otherwise, PHP's native serializer is used as a fallback.

## Testing

```bash
composer install
vendor/bin/phpunit
```

A running Redis server on `127.0.0.1:6379` is expected for integration tests.

## License

MIT
