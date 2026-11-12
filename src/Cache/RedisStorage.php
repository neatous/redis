<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\Journal;
use Nette\InvalidArgumentException;
use Nette\InvalidStateException;
use Nette\NotSupportedException;

/**
 * Nette cache storage backed by Redis (phpredis).
 *
 * Items are stored as a random stamp followed by an envelope with their metadata (callbacks, sliding expiration,
 * dependent items), packed by the connection's serializer and compression, so that every dependency of
 * Nette\Caching\Cache is honored and even `false` can be cached. The stamp lets conditional operations (sliding
 * expiration, removing an invalid item) run atomically only if the item was not rewritten meanwhile.
 * Namespaces are invalidated in O(1) by replacing their version token; the key of an item contains the versions
 * of its namespace and of all its ancestors, so cleaning a namespace also invalidates the derived (nested) ones.
 */
final class RedisStorage implements Storage
{
	public const int DEFAULT_TTL = 3600;

	/** envelope structure */
	private const string
		META_DATA = 'd',
		META_CALLBACKS = 'c',
		META_DELTA = 's',
		META_ITEMS = 'i';

	/** extends the expiration of KEYS[1] to ARGV[2] seconds if it still has the stamp ARGV[1] */
	private const string SLIDE_SCRIPT = <<<'LUA'
		if redis.call('GETRANGE', KEYS[1], 0, 15) == ARGV[1] then
			return redis.call('EXPIRE', KEYS[1], ARGV[2])
		end
		return 0
		LUA;

	/** deletes KEYS[1] if it still has the stamp ARGV[1] */
	private const string DELETE_IF_SCRIPT = <<<'LUA'
		if redis.call('GETRANGE', KEYS[1], 0, 15) == ARGV[1] then
			return redis.call('DEL', KEYS[1])
		end
		return 0
		LUA;

	/** Probability (0-1) that a write also runs one bounded step of the garbage collection. */
	public static float $gcProbability = 0.001;

	/**
	 * @param \Redis $redis connection with the PHP or igbinary serializer and no OPT_PREFIX
	 *                      (as created by RedisFactory)
	 * @param ?Journal $journal a RedisJournal must use the same connection
	 * @param ?int $defaultTtl seconds for items without Cache::Expire (at least 1); null keeps them until invalidated
	 */
	public function __construct(
		private readonly \Redis $redis,
		private readonly RedisKeyBuilder $keyBuilder,
		private readonly ?Journal $journal = null,
		private readonly ?int $defaultTtl = self::DEFAULT_TTL,
	) {
		if ($defaultTtl !== null && $defaultTtl < 1) {
			throw new InvalidArgumentException("Default TTL must be at least 1 second or null, $defaultTtl given.");
		}

		$serializer = $redis->getOption(\Redis::OPT_SERIALIZER);
		if (!in_array($serializer, [\Redis::SERIALIZER_PHP, \Redis::SERIALIZER_IGBINARY], true)) {
			throw new InvalidArgumentException(
				'Redis connection must use the PHP or igbinary serializer (Redis::OPT_SERIALIZER), which restore PHP '
				. 'objects; create it with RedisFactory.',
			);
		}

		$keyPrefix = $redis->getOption(\Redis::OPT_PREFIX);
		if (is_string($keyPrefix) && $keyPrefix !== '') {
			throw new InvalidArgumentException(
				'Redis connection must not use Redis::OPT_PREFIX (SCAN patterns ignore it), use the key prefix.',
			);
		}

		if ($journal instanceof RedisJournal && !$journal->usesConnection($redis)) {
			throw new InvalidArgumentException(
				'RedisJournal must use the same Redis connection as RedisStorage, so that items and journal entries '
				. 'are changed atomically.',
			);
		}
	}


	public function read(string $key): mixed
	{
		[$redisKey, $versions] = $this->resolveItem($key, create: false);
		if ($redisKey === null) {
			return null; // the namespace has no version token, so no valid item can exist
		}

		$item = $this->decode($this->redis->rawCommand('GET', $redisKey));
		if ($item === null) {
			return null; // missing item or a value not written by this storage
		}

		[$stamp, $meta] = $item;
		if (
			(isset($meta[self::META_CALLBACKS]) && !$this->checkCallbacks($meta[self::META_CALLBACKS]))
			|| (isset($meta[self::META_ITEMS]) && !$this->checkItems($meta[self::META_ITEMS], [$key => true]))
		) {
			$this->removeIfUnchanged($key, $redisKey, $stamp, $versions);
			return null;
		}

		if (isset($meta[self::META_DELTA]) && is_int($meta[self::META_DELTA])) {
			// only if the item was not rewritten since it was read
			LuaScript::run($this->redis, self::SLIDE_SCRIPT, [$redisKey, $stamp, (string) $meta[self::META_DELTA]], 1);
		}

		return $meta[self::META_DATA];
	}


	/** @param array<string, mixed> $dependencies */
	public function write(string $key, mixed $data, array $dependencies): void
	{
		$this->assertJournal($dependencies);
		$ttl = $this->determineTtl($dependencies);
		if ($ttl !== null && $ttl <= 0) {
			$this->remove($key);
			return;
		}

		$meta = [self::META_DATA => $data];

		if (isset($dependencies[Cache::Expire]) && $ttl !== null && !empty($dependencies[Cache::Sliding])) {
			$meta[self::META_DELTA] = $ttl;
		}

		if (isset($dependencies[Cache::Callbacks])) {
			$meta[self::META_CALLBACKS] = (array) $dependencies[Cache::Callbacks];
		}

		if (isset($dependencies[Cache::Items])) {
			$meta[self::META_ITEMS] = array_map(
				static fn (?array $item): ?string => $item[0] ?? null,
				$this->readItems(KeyScanner::toStringList($dependencies[Cache::Items])),
			);
		}

		[$redisKey, $versions] = $this->resolveItem($key, create: true);
		$redisKey ?? throw new InvalidStateException('Cannot resolve the namespace version of the item.');

		$value = $this->encode($meta);

		if ($this->journal instanceof RedisJournal) {
			// item and journal entry in one atomic script; the entry is always replaced, so a rewrite without tags
			// or priority drops the previous one
			// a namespace invalidated meanwhile makes the write a no-op: the item would be unreachable anyway
			$this->journal->writeWithItem(
				$key,
				$dependencies,
				$ttl === null ? ['set', $redisKey, null, $value] : ['setex', $redisKey, $ttl, $value],
				$versions,
			);
		} else {
			$this->redis->clearLastError();
			$result = $ttl === null
				? $this->redis->rawCommand('SET', $redisKey, $value)
				: $this->redis->rawCommand('SETEX', $redisKey, (string) $ttl, $value);
			if ($result !== true) {
				throw new \RedisException('Cannot write the cache item: ' . $this->redis->getLastError());
			}

			// other journals are used like Nette's FileStorage does: only for items with tags or priority
			if ($this->journal !== null && self::usesJournal($dependencies)) {
				$this->journal->write($key, $dependencies);
			}
		}

		if (self::$gcProbability <= 0 || mt_rand() / mt_getrandmax() >= self::$gcProbability) {
			return;
		}

		try {
			$this->collectGarbage(full: false);
		} catch (\RedisException) {
			// housekeeping only: the item has been written and the next step retries
		}
	}


	public function remove(string $key): void
	{
		[$redisKey, $versions] = $this->resolveItem($key, create: false);

		if ($this->journal instanceof RedisJournal) {
			$item = $redisKey === null ? null : ['del', $redisKey, null, null];
			$this->journal->writeWithItem($key, [], $item, $versions);
		} elseif ($redisKey !== null) {
			$this->redis->del($redisKey); // other journals keep the entry, as with Nette's FileStorage
		}
	}


	/** @param array<string, mixed> $conditions */
	public function clean(array $conditions): void
	{
		$namespaces = KeyScanner::toStringList($conditions[Cache::Namespaces] ?? []);
		if (!empty($conditions[Cache::All]) || in_array('', $namespaces, true)) {
			// the root namespace contains every other one; the journal goes first, so that an item written
			// concurrently may lose its entry only together with the item itself
			$this->journal?->clean([Cache::All => true]);
			$this->deleteCacheKeys();
			return;
		}

		if ($conditions === []) {
			$this->collectGarbage(full: true);
			return;
		}

		$this->assertJournal($conditions);

		foreach ($namespaces as $namespace) {
			// XX: a namespace without a token has no valid items, so it needs no new key
			$versionKey = $this->keyBuilder->buildVersionKey($namespace);
			$this->redis->clearLastError();
			if (
				$this->redis->rawCommand('SET', $versionKey, self::generateVersion(), 'XX') === false
				&& $this->redis->getLastError() !== null
			) {
				throw new \RedisException('Cannot invalidate the namespace: ' . $this->redis->getLastError());
			}
		}

		if ($this->journal === null || !self::usesJournal($conditions)) {
			return;
		}

		$journalConditions = array_intersect_key($conditions, [Cache::Tags => true, Cache::Priority => true]);
		if ($this->journal instanceof RedisJournal) {
			// items and their entries are deleted together, atomically per batch, so an interrupted clean leaves
			// the rest findable by a retry
			foreach (array_chunk($this->journal->collect($journalConditions), KeyScanner::BATCH) as $chunk) {
				$this->journal->removeEntries($this->resolveItemsForGc($chunk), $journalConditions);
			}

			return;
		}

		$keys = $this->journal->clean($journalConditions) ?? [];
		$redisKeys = array_values(array_filter($this->buildKeys($keys, create: false)));
		foreach (array_chunk($redisKeys, KeyScanner::BATCH) as $chunk) {
			$this->redis->del($chunk); // the journal has already dropped the entries
		}
	}


	/**
	 * Locking is not used by Nette\Caching\Cache 4 and was a no-op hint in 3.x.
	 */
	public function lock(string $_key): void
	{
	}


	/**
	 * @param array<string, mixed> $dependencies dependencies or clean conditions
	 */
	private function assertJournal(array $dependencies): void
	{
		if ($this->journal !== null || !self::usesJournal($dependencies)) {
			return;
		}

		throw new InvalidStateException(
			'CacheJournal has not been provided, tags and priorities need RedisStorage with a journal.',
		);
	}


	/**
	 * @param array<string, mixed> $dependencies dependencies or clean conditions
	 */
	private static function usesJournal(array $dependencies): bool
	{
		return !empty($dependencies[Cache::Tags]) || isset($dependencies[Cache::Priority]);
	}


	/**
	 * @param array<string, ?string> $versions version keys => tokens the item key was resolved with
	 */
	private function removeIfUnchanged(string $key, string $redisKey, string $stamp, array $versions): void
	{
		if ($this->journal instanceof RedisJournal) {
			$this->journal->writeWithItem($key, [], ['delif', $redisKey, null, $stamp], $versions);
		} else {
			LuaScript::run($this->redis, self::DELETE_IF_SCRIPT, [$redisKey, $stamp], 1);
		}
	}


	/**
	 * @param array<string, mixed> $meta
	 */
	private function encode(array $meta): string
	{
		return bin2hex(random_bytes(RedisJournal::STAMP_LENGTH / 2)) . $this->redis->_pack($meta);
	}


	/**
	 * @return array{string, array<mixed>}|null stamp and envelope, null for a missing or foreign value
	 */
	private function decode(mixed $raw): ?array
	{
		if (!is_string($raw) || strlen($raw) <= RedisJournal::STAMP_LENGTH) {
			return null;
		}

		$stamp = substr($raw, 0, RedisJournal::STAMP_LENGTH);
		if (!ctype_xdigit($stamp)) {
			return null;
		}

		$meta = @$this->redis->_unpack(substr($raw, RedisJournal::STAMP_LENGTH)); // @ corrupted data reads as a miss

		return is_array($meta) && array_key_exists(self::META_DATA, $meta) ? [$stamp, $meta] : null;
	}


	/**
	 * Resolves the Redis key of one item together with the version tokens it was built from (version key => token,
	 * null = absent), which the journal script re-checks. The key is null when a namespace has no token.
	 *
	 * @return array{?string, array<string, ?string>}
	 */
	private function resolveItem(string $key, bool $create): array
	{
		[$namespace, $name] = $this->parseKey($key);
		$tokens = $this->readTokens($this->getAncestorPaths($namespace));
		if ($create) {
			foreach ($tokens as $path => $token) {
				$tokens[$path] = $token ?? $this->createVersion((string) $path);
			}
		}

		$expected = [];
		foreach ($tokens as $path => $token) {
			$expected[$this->keyBuilder->buildVersionKey((string) $path)] = $token;
		}

		$redisKey = in_array(null, $tokens, true)
			? null
			: $this->keyBuilder->build($namespace, array_map(strval(...), array_values($tokens)), $name);

		return [$redisKey, $expected];
	}


	/**
	 * Builds the Redis keys of the given storage keys, resolving the versions of all their namespaces at once.
	 * Without $create, a key whose namespace has no version token maps to null (no valid item can exist there),
	 * so that reads never create keys.
	 *
	 * @param list<string> $keys
	 * @return array<string, ?string> storage key => Redis key
	 */
	private function buildKeys(array $keys, bool $create): array
	{
		$parsed = [];
		foreach ($keys as $key) {
			$parsed[$key] = $this->parseKey($key);
		}

		$namespaces = [];
		foreach ($parsed as [$namespace]) {
			$namespaces[$namespace] = true;
		}

		$versions = $this->getVersions(array_map(strval(...), array_keys($namespaces)), $create);

		$redisKeys = [];
		foreach ($parsed as $key => [$namespace, $name]) {
			$redisKeys[(string) $key] = isset($versions[$namespace])
				? $this->keyBuilder->build($namespace, $versions[$namespace], $name)
				: null;
		}

		return $redisKeys;
	}


	/**
	 * @return array{string, string}
	 */
	private function parseKey(string $key): array
	{
		$pos = strrpos($key, Cache::NamespaceSeparator);
		if ($pos === false) {
			return ['', $key];
		}

		return [substr($key, 0, $pos), substr($key, $pos + 1)];
	}


	/**
	 * Returns the version tokens of the namespaces and all their ancestors, with one MGET.
	 * With $create, a missing token is created, so that losing it (eviction, manual delete) invalidates
	 * the namespace instead of resurrecting stale items; without it, a namespace with a missing token is left out.
	 *
	 * @param list<string> $namespaces
	 * @return array<string, list<string>> namespace => versions, outermost first
	 */
	private function getVersions(array $namespaces, bool $create): array
	{
		$paths = [];
		$allPaths = [];
		foreach ($namespaces as $namespace) {
			$paths[$namespace] = $this->getAncestorPaths($namespace);
			foreach ($paths[$namespace] as $path) {
				$allPaths[$path] = true;
			}
		}

		$allPaths = array_map(strval(...), array_keys($allPaths));
		$tokens = [];

		foreach ($this->readTokens($allPaths) as $path => $token) {
			if ($token !== null) {
				$tokens[$path] = $token;
			} elseif ($create) {
				$tokens[$path] = $this->createVersion($path);
			}
		}

		$result = [];
		foreach ($paths as $namespace => $namespacePaths) {
			$namespaceVersions = [];
			foreach ($namespacePaths as $path) {
				if (!isset($tokens[$path])) {
					continue 2;
				}

				$namespaceVersions[] = $tokens[$path];
			}

			$result[(string) $namespace] = $namespaceVersions;
		}

		return $result;
	}


	/**
	 * Reads the version tokens of the namespace paths with one raw MGET (bypassing OPT_SERIALIZER).
	 *
	 * @param list<string> $paths
	 * @return array<string, ?string> path => token, null if missing
	 */
	private function readTokens(array $paths): array
	{
		if ($paths === []) {
			return [];
		}

		$this->redis->clearLastError();
		$values = $this->redis->rawCommand('MGET', ...array_map($this->keyBuilder->buildVersionKey(...), $paths));
		if (!is_array($values)) {
			// never treat a failure as "all missing": GC would delete live items as orphaned
			throw new \RedisException('Cannot read namespace versions: ' . $this->redis->getLastError());
		}

		$values = array_values($values);

		$tokens = [];
		foreach ($paths as $i => $path) {
			$token = $values[$i] ?? null;
			$tokens[$path] = is_string($token) || is_int($token) ? (string) $token : null;
		}

		return $tokens;
	}


	/**
	 * Resolves storage keys for the journal scripts: the item key (null when a namespace version is missing) and the
	 * version tokens it was resolved with, which the scripts re-check atomically.
	 *
	 * @param list<string> $keys
	 * @return array<string, array{?string, array<string, ?string>}>
	 */
	private function resolveItemsForGc(array $keys): array
	{
		$parsed = [];
		$allPaths = [];
		foreach ($keys as $key) {
			[$namespace, $name] = $this->parseKey($key);
			$paths = $this->getAncestorPaths($namespace);
			$parsed[$key] = [$namespace, $name, $paths];
			foreach ($paths as $path) {
				$allPaths[$path] = true;
			}
		}

		$tokens = $this->readTokens(array_map(strval(...), array_keys($allPaths)));

		$items = [];
		foreach ($parsed as $key => [$namespace, $name, $paths]) {
			$versions = [];
			$expected = [];
			foreach ($paths as $path) {
				$token = $tokens[$path] ?? null;
				$expected[$this->keyBuilder->buildVersionKey($path)] = $token;
				$versions[] = $token;
			}

			$complete = !in_array(null, $versions, true);
			$items[(string) $key] = [
				$complete ? $this->keyBuilder->build($namespace, array_map(strval(...), $versions), $name) : null,
				$expected,
			];
		}

		return $items;
	}


	/**
	 * @return list<string> the namespace and its ancestors, outermost first; empty for the root namespace
	 */
	private function getAncestorPaths(string $namespace): array
	{
		if ($namespace === '') {
			return [];
		}

		$paths = [];
		$path = null;
		foreach (explode(Cache::NamespaceSeparator, $namespace) as $part) {
			$path = $path === null ? $part : $path . Cache::NamespaceSeparator . $part;
			$paths[] = $path;
		}

		return $paths;
	}


	private function createVersion(string $namespace): string
	{
		$versionKey = $this->keyBuilder->buildVersionKey($namespace);
		$version = self::generateVersion();
		if ($this->redis->rawCommand('SET', $versionKey, $version, 'NX') === true) {
			return $version;
		}

		$current = $this->redis->rawCommand('GET', $versionKey); // set concurrently by another process

		return is_string($current) || is_int($current) ? (string) $current : $version;
	}


	private static function generateVersion(): string
	{
		return bin2hex(random_bytes(8));
	}


	/**
	 * Reads the given items at once.
	 *
	 * @param list<string> $keys
	 * @return array<string, array{string, array<mixed>}|null> storage key => [stamp, envelope], null if missing
	 */
	private function readItems(array $keys): array
	{
		$redisKeys = $this->buildKeys(array_values(array_unique($keys)), create: false);
		$existing = array_filter($redisKeys);
		$values = $existing === [] ? [] : $this->redis->rawCommand('MGET', ...array_values($existing));
		if (!is_array($values)) {
			throw new \RedisException('Cannot read dependent items: ' . $this->redis->getLastError());
		}

		$values = $existing === [] ? [] : array_combine(array_keys($existing), array_values($values));

		$items = [];
		foreach (array_keys($redisKeys) as $key) {
			$items[(string) $key] = $this->decode($values[$key] ?? null);
		}

		return $items;
	}


	/**
	 * Verifies the callbacks stored with the item; malformed metadata invalidates the item.
	 */
	private function checkCallbacks(mixed $callbacks): bool
	{
		if (!is_array($callbacks) || !array_is_list($callbacks)) {
			return false;
		}

		$list = [];
		foreach ($callbacks as $callback) {
			// syntax only: Nette's own checks like [Cache::class, 'checkFile'] are private, called from Cache
			if (
				!is_array($callback)
				|| !array_is_list($callback)
				|| !isset($callback[0])
				|| !is_callable($callback[0], syntax_only: true)
			) {
				return false;
			}

			$list[] = $callback;
		}

		return Cache::checkCallbacks($list);
	}


	/**
	 * Verifies that the items the cached item depends on are unchanged and themselves still valid
	 * (their callbacks and, recursively, their own dependent items), as Nette's FileStorage does.
	 *
	 * @param mixed $items storage key => stamp recorded when the dependent item was written
	 * @param array<string, true> $visited items on the current path, guards against cycles
	 */
	private function checkItems(mixed $items, array $visited): bool
	{
		if (!is_array($items)) {
			return false;
		}

		$current = $this->readItems(array_map(strval(...), array_keys($items)));
		foreach ($items as $key => $stamp) {
			$key = (string) $key;
			[$currentStamp, $item] = $current[$key] ?? [null, null];
			if ($currentStamp !== $stamp) {
				return false;
			}

			if ($item === null || isset($visited[$key])) {
				continue; // missing at write time and still missing, or a cycle
			}

			$valid = (!isset($item[self::META_CALLBACKS]) || $this->checkCallbacks($item[self::META_CALLBACKS]))
				&& (
					!isset($item[self::META_ITEMS])
					|| $this->checkItems($item[self::META_ITEMS], $visited + [$key => true])
				);
			if (!$valid) {
				return false;
			}
		}

		return true;
	}


	/** @param array<string, mixed> $dependencies */
	private function determineTtl(array $dependencies): ?int
	{
		if (!isset($dependencies[Cache::Expire])) {
			return $this->defaultTtl;
		}

		$expire = $dependencies[Cache::Expire];
		if ($expire instanceof \DateTimeInterface) {
			return $expire->getTimestamp() - time();
		}

		if (is_int($expire) || is_numeric($expire)) {
			return (int) $expire;
		}

		if (!is_string($expire)) {
			throw new NotSupportedException(
				'Cache::Expire must be a number of seconds, a time string or DateTimeInterface.',
			);
		}

		try {
			return (new \DateTimeImmutable($expire))->getTimestamp() - time(); // e.g. '20 minutes', as Nette accepts
		} catch (\Exception $e) {
			throw new NotSupportedException("Invalid Cache::Expire '$expire'.", 0, $e);
		}
	}


	/**
	 * Deletes all keys of the cache, in the current and the previous key format, in one SCAN pass.
	 */
	private function deleteCacheKeys(): void
	{
		KeyScanner::deleteByPattern(
			$this->redis,
			$this->keyBuilder->buildCacheRootsPattern(),
			$this->keyBuilder->isCacheKey(...),
		);
	}


	/**
	 * Removes items orphaned by a namespace invalidation and journal entries of items that no longer exist.
	 * The full run walks the whole keyspace; otherwise one bounded step continues where the previous one ended.
	 */
	private function collectGarbage(bool $full): void
	{
		$batches = $full
			? KeyScanner::scan($this->redis, $this->keyBuilder->buildItemPattern())
			: [KeyScanner::scanStep(
				$this->redis,
				$this->keyBuilder->buildItemPattern(),
				$this->keyBuilder->buildGcCursorKey('items'),
			)];

		foreach ($batches as $itemKeys) {
			$this->deleteOrphanedItems($itemKeys);
		}

		if (!$this->journal instanceof RedisJournal) {
			return;
		}

		$this->journal->collectGarbage(
			$this->resolveItemsForGc(...),
			$full ? null : $this->keyBuilder->buildGcCursorKey('journal'),
		);
	}


	/**
	 * @param list<string> $itemKeys
	 */
	private function deleteOrphanedItems(array $itemKeys): void
	{
		$parsed = [];
		$namespaces = [];
		foreach ($itemKeys as $itemKey) {
			$info = $this->keyBuilder->parseItemKey($itemKey);
			if ($info === null) {
				continue;
			}

			$parsed[$itemKey] = $info;
			$namespaces[$info[0]] = true;
		}

		$current = $this->getVersions(array_map(strval(...), array_keys($namespaces)), create: false);

		$orphaned = [];
		foreach ($parsed as $itemKey => [$namespace, $versions]) {
			if (($current[$namespace] ?? null) === $versions) {
				continue;
			}

			$orphaned[] = (string) $itemKey;
		}

		if ($orphaned === []) {
			return;
		}

		$this->redis->unlink($orphaned);
	}
}
