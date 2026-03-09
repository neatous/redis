<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\Journal;

final class RedisStorage implements Storage
{
	private const int DEFAULT_TTL = 3600;

	public function __construct(
		private readonly \Redis $redis,
		private readonly RedisKeyBuilder $keyBuilder,
		private readonly ?Journal $journal = null,
	) {
	}

	public function read(string $key): mixed
	{
		[$namespace, $name] = $this->parseKey($key);
		$version = $this->getNamespaceVersion($namespace);
		$redisKey = $this->keyBuilder->build($namespace, $version, $name);

		$value = $this->redis->get($redisKey);

		return $value === false ? null : $value;
	}

	/** @param array<string, mixed> $dependencies */
	public function write(string $key, mixed $data, array $dependencies): void
	{
		[$namespace, $name] = $this->parseKey($key);
		$version = $this->getNamespaceVersion($namespace);
		$redisKey = $this->keyBuilder->build($namespace, $version, $name);

		$ttl = $this->determineTtl($dependencies);

		$this->redis->setex($redisKey, $ttl, $data);

		if ($this->journal === null || (!isset($dependencies[Cache::Tags]) && !isset($dependencies[Cache::Priority]))) {
			return;
		}

		$this->journal->write($key, $dependencies);
	}

	public function remove(string $key): void
	{
		[$namespace, $name] = $this->parseKey($key);
		$version = $this->getNamespaceVersion($namespace);
		$redisKey = $this->keyBuilder->build($namespace, $version, $name);

		$this->redis->del($redisKey);
	}

	/** @param array<string, mixed> $conditions */
	public function clean(array $conditions): void
	{
		if (isset($conditions[Cache::All])) {
			$this->redis->flushDb();
			$this->journal?->clean($conditions);
			return;
		}

		if (isset($conditions[Cache::Namespaces])) {
			/** @var array<string> $namespaces */
			$namespaces = (array) $conditions[Cache::Namespaces];
			foreach ($namespaces as $namespace) {
				$versionKey = $this->keyBuilder->buildVersionKey($namespace);
				$this->redis->incr($versionKey);
			}
		}

		if ($this->journal === null || (!isset($conditions[Cache::Tags]) && !isset($conditions[Cache::Priority]))) {
			return;
		}

		$keys = $this->journal->clean($conditions);
		if ($keys === null) {
			return;
		}

		foreach ($keys as $key) {
			$this->remove($key);
		}
	}

	public function lock(string $_key): void
	{
	}

	/**
	 * @return array{string, string}
	 */
	private function parseKey(string $key): array
	{
		$pos = strrpos($key, "\x00");
		if ($pos === false) {
			return ['', $key];
		}

		return [substr($key, 0, $pos), substr($key, $pos + 1)];
	}

	private function getNamespaceVersion(string $namespace): int
	{
		if ($namespace === '') {
			return 1;
		}

		$versionKey = $this->keyBuilder->buildVersionKey($namespace);
		$version = $this->redis->get($versionKey);

		if (!is_string($version) && !is_int($version)) {
			return 1;
		}

		return (int) $version;
	}

	/** @param array<string, mixed> $dependencies */
	private function determineTtl(array $dependencies): int
	{
		if (!isset($dependencies[Cache::Expire])) {
			return self::DEFAULT_TTL;
		}

		$expire = $dependencies[Cache::Expire];
		if ($expire instanceof \DateTimeInterface) {
			return max(1, $expire->getTimestamp() - time());
		}

		if (!is_string($expire) && !is_int($expire)) {
			return self::DEFAULT_TTL;
		}

		return max(1, (int) $expire);
	}
}
