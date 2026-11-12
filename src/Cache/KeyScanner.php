<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

/**
 * SCAN helpers shared by the storage and the journal.
 *
 * @internal
 */
final class KeyScanner
{
	public const int BATCH = 1000;


	/**
	 * Iterates over all keys matching the pattern in batches, without blocking the server.
	 *
	 * @return \Generator<list<string>>
	 */
	public static function scan(\Redis $redis, string $pattern): \Generator
	{
		$iterator = null;
		do {
			$previous = $iterator;
			$keys = $redis->scan($iterator, $pattern, self::BATCH);
			if ($keys === false && $iterator === $previous && $previous !== null) {
				break; // failed without advancing the cursor; do not spin
			}

			if ($keys === false || $keys === []) {
				continue;
			}

			yield self::toStringList($keys);
		} while ($iterator !== 0 && $iterator !== null);
	}


	/**
	 * Returns one batch of keys matching the pattern, continuing from the cursor persisted under $cursorKey,
	 * so that repeated calls walk the whole keyspace in small bounded steps.
	 *
	 * @return list<string>
	 */
	public static function scanStep(\Redis $redis, string $pattern, string $cursorKey): array
	{
		$iterator = self::loadCursor($redis, $cursorKey);
		$keys = $redis->scan($iterator, $pattern, self::BATCH);
		self::saveCursor($redis, $cursorKey, $iterator);

		return $keys === false ? [] : self::toStringList($keys);
	}


	/**
	 * Iterates over the members of a sorted set in batches (all of them, or one step with a persisted cursor).
	 *
	 * @return \Generator<list<string>>
	 */
	public static function zScan(\Redis $redis, string $key, ?string $cursorKey = null): \Generator
	{
		// raw ZSCAN: members are returned as stored, independent of the connection's serializer
		$cursor = $cursorKey === null ? '0' : (string) (self::loadCursor($redis, $cursorKey) ?? 0);
		do {
			$reply = $redis->rawCommand('ZSCAN', $key, $cursor, 'COUNT', (string) self::BATCH);
			if (!is_array($reply) || !isset($reply[0], $reply[1]) || !is_array($reply[1])) {
				$cursor = '0';
				break;
			}

			$cursor = is_string($reply[0]) || is_int($reply[0]) ? (string) $reply[0] : '0';
			$members = [];
			foreach (array_values($reply[1]) as $i => $value) {
				if ($i % 2 !== 0) {
					continue; // scores
				}

				$members[] = $value;
			}

			if ($members === []) {
				continue;
			}

			yield self::toStringList($members);
		} while ($cursorKey === null && $cursor !== '0');

		if ($cursorKey === null) {
			return;
		}

		self::saveCursor($redis, $cursorKey, (int) $cursor);
	}


	/**
	 * Deletes (UNLINK) all keys matching the pattern, optionally only those accepted by the filter.
	 *
	 * @param ?\Closure(string): bool $filter
	 */
	public static function deleteByPattern(\Redis $redis, string $pattern, ?\Closure $filter = null): void
	{
		foreach (self::scan($redis, $pattern) as $keys) {
			if ($filter !== null) {
				$keys = array_values(array_filter($keys, $filter));
			}

			if ($keys === []) {
				continue;
			}

			$redis->unlink($keys);
		}
	}


	/**
	 * @return list<string>
	 */
	public static function toStringList(mixed $values): array
	{
		$list = [];
		foreach (is_array($values) ? $values : [$values] as $value) {
			if (!is_string($value) && !is_int($value) && !is_float($value) && !$value instanceof \Stringable) {
				continue;
			}

			$list[] = (string) $value;
		}

		return $list;
	}


	private static function loadCursor(\Redis $redis, string $cursorKey): ?int
	{
		$cursor = $redis->rawCommand('GET', $cursorKey);
		$cursor = is_string($cursor) || is_int($cursor) ? (int) $cursor : 0;

		return $cursor > 0 ? $cursor : null; // phpredis starts a scan with null; 0 means "finished"
	}


	private static function saveCursor(\Redis $redis, string $cursorKey, mixed $iterator): void
	{
		$redis->rawCommand('SET', $cursorKey, is_int($iterator) ? (string) $iterator : '0');
	}
}
