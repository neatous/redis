<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

use Nette\Caching\Cache;
use Nette\Caching\Storages\Journal;

/**
 * Tag and priority journal in Redis. Every change runs as one atomic Lua script, so concurrent writers, removers,
 * cleaners and the garbage collector can never leave the journal inconsistent with the items. Members are stored raw
 * (independent of the connection's serializer).
 *
 * Keys: {prefix}journal:tag:{tag} (set of storage keys), {prefix}journal:entry:{key} (set of the key's tags),
 * {prefix}journal:priority (sorted set of storage keys).
 */
final class RedisJournal implements Journal
{
	private const string PREFIX = 'journal:';

	/** length of the random stamp that precedes every stored item value */
	public const int STAMP_LENGTH = 16;

	/**
	 * Optionally writes or deletes the item (KEYS[3]), then replaces the entry of the member — only if the namespace
	 * version tokens the item key was resolved with are unchanged (otherwise the entry may already describe an item
	 * written under a newer version, and the stale operation is dropped: returns 0).
	 * KEYS: entry set, priority zset, [item]. ARGV: op (none|set|setex|del|delif), ttl, value (delif: expected stamp),
	 * member, tag key prefix, priority ('' = none), count of version keys, pairs of (version key, expected token,
	 * '' = absent), tags...
	 * 'delif' deletes the item only if it still has the expected stamp; otherwise nothing changes (returns 0).
	 */
	private const string WRITE_SCRIPT = <<<'LUA'
		local count = tonumber(ARGV[7])
		for j = 0, count - 1 do
			if (redis.call('GET', ARGV[8 + j * 2]) or '') ~= ARGV[9 + j * 2] then
				return 0
			end
		end
		local op = ARGV[1]
		if op == 'delif' then
			if redis.call('GETRANGE', KEYS[3], 0, 15) ~= ARGV[3] then
				return 0
			end
			redis.call('DEL', KEYS[3])
		elseif op == 'setex' then
			redis.call('SETEX', KEYS[3], ARGV[2], ARGV[3])
		elseif op == 'set' then
			redis.call('SET', KEYS[3], ARGV[3])
		elseif op == 'del' then
			redis.call('DEL', KEYS[3])
		end
		local member, tagPrefix = ARGV[4], ARGV[5]
		for _, tag in ipairs(redis.call('SMEMBERS', KEYS[1])) do
			redis.call('SREM', tagPrefix .. tag, member)
		end
		redis.call('DEL', KEYS[1])
		redis.call('ZREM', KEYS[2], member)
		for i = 8 + count * 2, #ARGV do
			redis.call('SADD', tagPrefix .. ARGV[i], member)
			redis.call('SADD', KEYS[1], ARGV[i])
		end
		if ARGV[6] ~= '' then
			redis.call('ZADD', KEYS[2], ARGV[6], member)
		end
		return 1
		LUA;

	/**
	 * Removes the entries of members, re-checking inside the script that the namespace versions their item keys were
	 * resolved with are unchanged (otherwise the member is skipped: its old item is orphaned anyway and a newer entry
	 * must stay). Mode 'gc' removes an entry only if its item does not exist; mode 'clean' deletes the item with it,
	 * but only if the member still matches the clean conditions (it may have been rewritten without the tag meanwhile).
	 * KEYS: priority zset. ARGV: entry key prefix, tag key prefix, mode, count of condition tags, condition tags...,
	 * condition priority ('' = none), then per member: member, item key ('' if the namespace has no version), count of
	 * version keys, pairs of (version key, expected token, '' = absent).
	 */
	private const string ENTRIES_SCRIPT = <<<'LUA'
		local entryPrefix, tagPrefix, mode = ARGV[1], ARGV[2], ARGV[3]
		local tagCount = tonumber(ARGV[4])
		local condTags = {}
		for t = 5, 4 + tagCount do
			condTags[ARGV[t]] = true
		end
		local condPriority = ARGV[5 + tagCount]
		local i, removed = 6 + tagCount, 0
		while i <= #ARGV do
			local member, itemKey, count = ARGV[i], ARGV[i + 1], tonumber(ARGV[i + 2])
			local unchanged = true
			for j = 0, count - 1 do
				local current = redis.call('GET', ARGV[i + 3 + j * 2]) or ''
				if current ~= ARGV[i + 4 + j * 2] then
					unchanged = false
				end
			end
			i = i + 3 + count * 2
			if unchanged and mode == 'clean' then
				local matches = false
				for _, tag in ipairs(tagCount > 0 and redis.call('SMEMBERS', entryPrefix .. member) or {}) do
					if condTags[tag] then
						matches = true
						break
					end
				end
				if not matches and condPriority ~= '' then
					local score = redis.call('ZSCORE', KEYS[1], member)
					matches = score ~= false and tonumber(score) <= tonumber(condPriority)
				end
				unchanged = matches
			end
			if unchanged then
				local remove = true
				if itemKey ~= '' then
					if mode == 'gc' then
						remove = redis.call('EXISTS', itemKey) == 0
					else
						redis.call('DEL', itemKey)
					end
				end
				if remove then
					for _, tag in ipairs(redis.call('SMEMBERS', entryPrefix .. member)) do
						redis.call('SREM', tagPrefix .. tag, member)
					end
					redis.call('DEL', entryPrefix .. member)
					redis.call('ZREM', KEYS[1], member)
					removed = removed + 1
				end
			end
		end
		return removed
		LUA;

	/**
	 * Removes the entries of the members. KEYS: priority zset. ARGV: entry key prefix, tag key prefix, members...
	 */
	private const string REMOVE_SCRIPT = <<<'LUA'
		local entryPrefix, tagPrefix = ARGV[1], ARGV[2]
		for i = 3, #ARGV do
			local member = ARGV[i]
			for _, tag in ipairs(redis.call('SMEMBERS', entryPrefix .. member)) do
				redis.call('SREM', tagPrefix .. tag, member)
			end
			redis.call('DEL', entryPrefix .. member)
			redis.call('ZREM', KEYS[1], member)
		end
		return 1
		LUA;


	public function __construct(
		private readonly \Redis $redis,
		private readonly string $prefix = 'app:',
	) {
		if ($prefix === '') {
			throw new \Nette\InvalidArgumentException('The key prefix must not be empty.');
		}
	}


	/**
	 * Whether the journal uses the given connection, so that items can be changed atomically with their entries.
	 */
	public function usesConnection(\Redis $redis): bool
	{
		return $this->redis === $redis;
	}


	/**
	 * Writes the entry without checking namespace versions (Journal interface; RedisStorage uses writeWithItem()).
	 *
	 * @param array<string, mixed> $dependencies
	 */
	public function write(string $key, array $dependencies): void
	{
		$this->writeWithItem($key, $dependencies, null, []);
	}


	/**
	 * Replaces the entry of the key and, in the same atomic script, applies the item operation:
	 * ['set', itemKey, null, value], ['setex', itemKey, ttl, value], ['del', itemKey, null, null]
	 * or ['delif', itemKey, null, expectedStamp].
	 *
	 * Nothing changes when one of the namespace version tokens differs from the expected one.
	 *
	 * @param array<string, mixed> $dependencies
	 * @param array{'set'|'setex'|'del'|'delif', string, ?int, ?string}|null $item
	 * @param array<string, ?string> $versions version keys => tokens the item key was resolved with (null = absent)
	 * @return bool false when nothing was changed (a namespace version changed, or 'delif' found a different item)
	 */
	public function writeWithItem(string $key, array $dependencies, ?array $item, array $versions): bool
	{
		$tags = array_values(array_unique(KeyScanner::toStringList($dependencies[Cache::Tags] ?? [])));
		$priority = self::toPriority($dependencies[Cache::Priority] ?? null);

		$keys = [$this->entryKey($key), $this->priorityKey()];
		if ($item !== null) {
			$keys[] = $item[1];
		}

		$args = [
			...$keys,
			$item[0] ?? 'none',
			(string) ($item[2] ?? ''),
			$item[3] ?? '',
			$key,
			$this->tagKeyPrefix(),
			$priority === null ? '' : (string) $priority,
			(string) count($versions),
		];
		foreach ($versions as $versionKey => $token) {
			array_push($args, (string) $versionKey, $token ?? '');
		}

		$result = LuaScript::run($this->redis, self::WRITE_SCRIPT, [...$args, ...$tags], count($keys));

		return $result !== 0;
	}


	/**
	 * @param array<string, mixed> $conditions
	 * @return list<string>|null
	 */
	public function clean(array $conditions): ?array
	{
		if (!empty($conditions[Cache::All])) {
			$this->cleanAll();
			return null;
		}

		$entries = $this->collect($conditions);
		foreach (array_chunk($entries, KeyScanner::BATCH) as $chunk) {
			LuaScript::run($this->redis, self::REMOVE_SCRIPT, [
				$this->priorityKey(),
				$this->entryKeyPrefix(),
				$this->tagKeyPrefix(),
				...$chunk,
			], 1);
		}

		return $entries;
	}


	public function cleanAll(): void
	{
		KeyScanner::deleteByPattern($this->redis, RedisKeyBuilder::escapePattern($this->prefix . self::PREFIX) . '*');
	}


	/**
	 * Returns the storage keys matching the tags or priority conditions, without changing anything.
	 *
	 * @param array<string, mixed> $conditions
	 * @return list<string>
	 */
	public function collect(array $conditions): array
	{
		$entries = [];

		$tags = KeyScanner::toStringList($conditions[Cache::Tags] ?? []);
		if ($tags !== []) {
			$pipe = $this->redis->multi(\Redis::PIPELINE);
			foreach ($tags as $tag) {
				$pipe->rawCommand('SMEMBERS', $this->tagKeyPrefix() . $tag);
			}

			$replies = $pipe->exec();
			if (!is_array($replies)) {
				throw new \RedisException('Cannot read the journal tag sets: ' . $this->redis->getLastError());
			}

			foreach ($replies as $found) {
				if (!is_array($found)) { // an error reply of one of the commands
					throw new \RedisException('Cannot read a journal tag set: ' . $this->redis->getLastError());
				}

				array_push($entries, ...KeyScanner::toStringList($found));
			}
		}

		$priority = self::toPriority($conditions[Cache::Priority] ?? null);
		if ($priority !== null) {
			$found = $this->redis->rawCommand('ZRANGEBYSCORE', $this->priorityKey(), '-inf', (string) $priority);
			if (!is_array($found)) {
				throw new \RedisException('Cannot read the journal priorities: ' . $this->redis->getLastError());
			}

			array_push($entries, ...KeyScanner::toStringList($found));
		}

		return array_values(array_unique($entries));
	}


	/**
	 * Removes the entries of the given items atomically per call; with $cleanConditions (tag/priority clean) the items
	 * still matching them are deleted with their entries, otherwise only entries whose items do not exist are removed
	 * (garbage collection).
	 *
	 * @param array<string, array{?string, array<string, ?string>}> $items storage key => [Redis key of the item or
	 *        null when its namespace has no version, version keys => tokens used to resolve it (null = absent)]
	 * @param array<string, mixed>|null $cleanConditions Cache::Tags / Cache::Priority of the clean, null for GC
	 */
	public function removeEntries(array $items, ?array $cleanConditions): void
	{
		if ($items === []) {
			return;
		}

		$tags = KeyScanner::toStringList($cleanConditions[Cache::Tags] ?? []);
		$priority = self::toPriority($cleanConditions[Cache::Priority] ?? null);
		$args = [
			$this->priorityKey(),
			$this->entryKeyPrefix(),
			$this->tagKeyPrefix(),
			$cleanConditions === null ? 'gc' : 'clean',
			(string) count($tags),
			...$tags,
			$priority === null ? '' : (string) $priority,
		];
		foreach ($items as $key => [$itemKey, $versions]) {
			array_push($args, (string) $key, $itemKey ?? '', (string) count($versions));
			foreach ($versions as $versionKey => $token) {
				array_push($args, (string) $versionKey, $token ?? '');
			}
		}

		LuaScript::run($this->redis, self::ENTRIES_SCRIPT, $args, 1);
	}


	/**
	 * Drops the entries of items that no longer exist in the storage (expired or orphaned).
	 *
	 * @param \Closure(list<string>): array<string, array{?string, array<string, ?string>}> $resolveItems
	 *        see removeEntries()
	 * @param ?string $cursorKey null walks all entries, otherwise one bounded step continues from this cursor
	 */
	public function collectGarbage(\Closure $resolveItems, ?string $cursorKey = null): void
	{
		$entryPrefix = $this->entryKeyPrefix();
		$pattern = RedisKeyBuilder::escapePattern($entryPrefix) . '*';
		$entryBatches = $cursorKey === null
			? KeyScanner::scan($this->redis, $pattern)
			: [KeyScanner::scanStep($this->redis, $pattern, $cursorKey . ':entries')];

		foreach ($entryBatches as $entryKeys) {
			$keys = array_map(
				static fn (string $entryKey): string => substr($entryKey, strlen($entryPrefix)),
				$entryKeys,
			);
			$this->removeEntries($resolveItems($keys), null);
		}

		$priorityBatches = KeyScanner::zScan(
			$this->redis,
			$this->priorityKey(),
			$cursorKey === null ? null : $cursorKey . ':priority',
		);
		foreach ($priorityBatches as $keys) {
			$this->removeEntries($resolveItems($keys), null);
		}
	}


	private static function toPriority(mixed $priority): ?int
	{
		return is_int($priority) || is_float($priority) || (is_string($priority) && is_numeric($priority))
			? (int) $priority // as Nette's journals cast it
			: null;
	}


	private function tagKeyPrefix(): string
	{
		return $this->prefix . self::PREFIX . 'tag:';
	}


	private function entryKeyPrefix(): string
	{
		return $this->prefix . self::PREFIX . 'entry:';
	}


	private function entryKey(string $key): string
	{
		return $this->entryKeyPrefix() . $key;
	}


	private function priorityKey(): string
	{
		return $this->prefix . self::PREFIX . 'priority';
	}
}
