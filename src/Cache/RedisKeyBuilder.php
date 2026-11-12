<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

final readonly class RedisKeyBuilder
{
	/** root of the current key format; distinct from the 0.9 format ({prefix}cache:…), so the two never share keys */
	private const string ROOT = 'cache2:';

	/** root of the 0.9 key format, only cleaned */
	private const string LEGACY_ROOT = 'cache:';


	public function __construct(
		private string $prefix,
	) {
		if ($prefix === '') {
			throw new \Nette\InvalidArgumentException('The key prefix must not be empty.');
		}
	}


	/**
	 * @param list<string> $versions versions of the namespace and all its ancestors, outermost first
	 */
	public function build(string $namespace, array $versions, string $key): string
	{
		return $this->prefix . self::ROOT . 'i:' . $namespace . ':' . implode('.', $versions) . ':' . sha1($key);
	}


	public function buildVersionKey(string $namespace): string
	{
		return $this->prefix . self::ROOT . 'ns:' . $namespace;
	}


	/**
	 * Key holding a cursor of the incremental garbage collection.
	 */
	public function buildGcCursorKey(string $name): string
	{
		return $this->prefix . self::ROOT . 'gc:' . $name;
	}


	/**
	 * SCAN pattern matching keys of both the current and the 0.9 format (and possibly other keys starting with
	 * '{prefix}cache'; filter them with isCacheKey()).
	 */
	public function buildCacheRootsPattern(): string
	{
		return self::escapePattern($this->prefix . 'cache') . '*';
	}


	/**
	 * Whether the key belongs to the cache, in the current or the 0.9 format.
	 */
	public function isCacheKey(string $key): bool
	{
		return str_starts_with($key, $this->prefix . self::ROOT)
			|| str_starts_with($key, $this->prefix . self::LEGACY_ROOT);
	}


	/**
	 * SCAN pattern matching cache items.
	 */
	public function buildItemPattern(): string
	{
		return self::escapePattern($this->prefix . self::ROOT . 'i:') . '*';
	}


	/**
	 * Extracts the namespace and versions from an item key built by build().
	 *
	 * @return array{string, list<string>}|null
	 */
	public function parseItemKey(string $itemKey): ?array
	{
		$itemPrefix = $this->prefix . self::ROOT . 'i:';
		if (!str_starts_with($itemKey, $itemPrefix)) {
			return null;
		}

		// parsed from the right: the hash and the versions contain no ':', the namespace may
		if (preg_match('~^(.*):([0-9a-zA-Z.]*):[0-9a-f]{40}$~s', substr($itemKey, strlen($itemPrefix)), $m) !== 1) {
			return null;
		}

		return [$m[1], $m[2] === '' ? [] : explode('.', $m[2])];
	}


	public static function escapePattern(string $value): string
	{
		return addcslashes($value, '?*[]^\\');
	}
}
