<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

use Nette\Caching\Cache;
use Nette\Caching\Storages\Journal;

final class RedisJournal implements Journal
{
	private const string PREFIX = 'journal:';


	public function __construct(
		private readonly \Redis $redis,
		private readonly string $prefix = 'app:',
	) {
	}


	/** @param array<string, mixed> $dependencies */
	public function write(string $key, array $dependencies): void
	{
		$this->cleanEntry($key);

		/** @var list<string> $tags */
		$tags = isset($dependencies[Cache::Tags])
			? array_unique(array_values((array) $dependencies[Cache::Tags]))
			: [];

		$priority = isset($dependencies[Cache::Priority])
			&& (is_int($dependencies[Cache::Priority]) || is_string($dependencies[Cache::Priority]))
			? (int) $dependencies[Cache::Priority]
			: null;

		if ($tags === [] && $priority === null) {
			return;
		}

		$pipe = $this->redis->multi(\Redis::PIPELINE);

		foreach ($tags as $tag) {
			$pipe->sAdd($this->tagKey($tag), $key);
			$pipe->sAdd($this->entryTagsKey($key), $tag);
		}

		if ($priority !== null) {
			$pipe->zAdd($this->priorityKey(), $priority, $key);
		}

		$pipe->exec();
	}


	/**
	 * @param array<string, mixed> $conditions
	 * @return list<string>|null
	 */
	public function clean(array $conditions): ?array
	{
		if (isset($conditions[Cache::All])) {
			$this->cleanAll();
			return null;
		}

		$entries = [];

		if (isset($conditions[Cache::Tags])) {
			/** @var list<string> $conditionTags */
			$conditionTags = (array) $conditions[Cache::Tags];
			foreach ($conditionTags as $tag) {
				/** @var list<string> $found */
				$found = $this->redis->sMembers($this->tagKey($tag));
				if ($found === []) {
					continue;
				}

				$this->cleanEntry($found);
				array_push($entries, ...$found);
			}
		}

		if (
			isset($conditions[Cache::Priority])
			&& (is_int($conditions[Cache::Priority]) || is_string($conditions[Cache::Priority]))
		) {
			/** @var list<string> $found */
			$found = $this->redis->zRangeByScore(
				$this->priorityKey(),
				'0',
				(string) (int) $conditions[Cache::Priority],
			);

			if ($found !== []) {
				$this->cleanEntry($found);
				array_push($entries, ...$found);
			}
		}

		/** @var list<string> */
		return array_values(array_unique($entries));
	}


	/** @param string|list<string> $keys */
	private function cleanEntry(string|array $keys): void
	{
		foreach (is_array($keys) ? $keys : [$keys] as $key) {
			/** @var list<string> $tags */
			$tags = $this->redis->sMembers($this->entryTagsKey($key));

			$pipe = $this->redis->multi(\Redis::PIPELINE);

			foreach ($tags as $tag) {
				$pipe->sRem($this->tagKey($tag), $key);
			}

			$pipe->del($this->entryTagsKey($key));
			$pipe->zRem($this->priorityKey(), $key);
			$pipe->exec();
		}
	}


	private function cleanAll(): void
	{
		$pattern = $this->prefix . self::PREFIX . '*';
		$iterator = null;
		$keys = [];

		do {
			$batch = $this->redis->scan($iterator, $pattern, 100);
			if ($batch === false) {
				continue;
			}

			array_push($keys, ...$batch);
		} while ($iterator !== 0 && $iterator !== false);

		if ($keys === []) {
			return;
		}

		$this->redis->del($keys);
	}


	private function tagKey(string $tag): string
	{
		return $this->prefix . self::PREFIX . 'tag:' . $tag;
	}


	private function entryTagsKey(string $key): string
	{
		return $this->prefix . self::PREFIX . 'tags:' . $key;
	}


	private function priorityKey(): string
	{
		return $this->prefix . self::PREFIX . 'priority';
	}
}
