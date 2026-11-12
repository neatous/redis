<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

use Netous\Redis\Cache\KeyScanner;
use Netous\Redis\Cache\RedisJournal;
use Netous\Redis\Cache\RedisKeyBuilder;
use Netous\Redis\Cache\RedisStorage;
use Netous\Redis\Client\RedisConfig;
use Netous\Redis\Client\RedisFactory;
use Nette\Caching\Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real Nette\Caching\Cache on top of RedisStorage + RedisJournal against a live Redis,
 * so that the contract with nette/caching (3.x and 4.x) is verified end to end.
 */
final class CacheIntegrationTest extends TestCase
{
	private const string PREFIX = 'test:';

	private \Redis $redis;
	private RedisStorage $storage;


	protected function setUp(): void
	{
		if (!extension_loaded('redis')) {
			self::markTestSkipped('ext-redis is not loaded.');
		}

		try {
			// the production client: igbinary serializer when phpredis supports it, PHP serializer otherwise
			$this->redis = TestSerializer::apply((new RedisFactory())->create(
				new RedisConfig('127.0.0.1', 6379, null, 15, 1.0, false, self::PREFIX),
			));
		} catch (\RedisException) {
			self::markTestSkipped('Cannot connect to Redis at 127.0.0.1:6379.');
		}

		$this->redis->flushDb();

		$this->storage = new RedisStorage(
			$this->redis,
			new RedisKeyBuilder(self::PREFIX),
			new RedisJournal($this->redis, self::PREFIX),
		);
	}


	protected function tearDown(): void
	{
		if (!isset($this->redis) || !$this->redis->isConnected()) {
			return;
		}

		$this->redis->flushDb();
		$this->redis->close();
	}


	#[Test]
	public function saveAndLoadRoundTripsComplexValues(): void
	{
		$cache = new Cache($this->storage, 'app');
		$value = ['list' => [1, 2, 3], 'object' => new \ArrayObject(['a' => 'b'])];

		$cache->save('key', $value);

		self::assertEquals($value, $cache->load('key'));
	}


	#[Test]
	public function relativeExpirationIsTranslatedToTtl(): void
	{
		$cache = new Cache($this->storage, 'app');

		$cache->save('key', 'value', [Cache::Expire => '10 minutes']);

		$ttl = $this->redis->ttl($this->findCacheItemKey());
		self::assertIsInt($ttl);
		self::assertGreaterThan(590, $ttl);
		self::assertLessThanOrEqual(600, $ttl);
	}


	#[Test]
	public function cleanByTagRemovesOnlyTaggedItems(): void
	{
		$cache = new Cache($this->storage, 'app');
		$cache->save('a', 'A', [Cache::Tags => ['tag-1']]);
		$cache->save('b', 'B', [Cache::Tags => ['tag-1', 'tag-2']]);
		$cache->save('c', 'C', [Cache::Tags => ['tag-2']]);
		$cache->save('d', 'D');

		$cache->clean([Cache::Tags => ['tag-1']]);

		self::assertNull($cache->load('a'));
		self::assertNull($cache->load('b'));
		self::assertSame('C', $cache->load('c'));
		self::assertSame('D', $cache->load('d'));
	}


	#[Test]
	public function cleanByTagWorksAcrossNamespaces(): void
	{
		$first = new Cache($this->storage, 'first');
		$second = new Cache($this->storage, 'second');
		$first->save('key', 'first', [Cache::Tags => ['shared']]);
		$second->save('key', 'second', [Cache::Tags => ['shared']]);

		$first->clean([Cache::Tags => ['shared']]);

		self::assertNull($first->load('key'));
		self::assertNull($second->load('key'));
	}


	#[Test]
	public function cleanAllOnRootCacheRemovesEverything(): void
	{
		$root = new Cache($this->storage);
		$namespaced = new Cache($this->storage, 'app');
		$root->save('key', 'root');
		$namespaced->save('key', 'namespaced', [Cache::Tags => ['tag']]);

		$root->clean([Cache::All => true]);

		self::assertNull($root->load('key'));
		self::assertNull($namespaced->load('key'));
	}


	#[Test]
	public function derivedCacheIsIsolatedFromParent(): void
	{
		$parent = new Cache($this->storage, 'parent');
		$child = $parent->derive('child');
		$parent->save('key', 'parent');
		$child->save('key', 'child');

		self::assertSame('parent', $parent->load('key'));
		self::assertSame('child', $child->load('key'));
	}


	#[Test]
	public function falseIsCachedAndGeneratorRunsOnce(): void
	{
		$cache = new Cache($this->storage, 'app');
		$calls = 0;
		$generator = static function () use (&$calls): bool {
			$calls++;
			return false;
		};

		$results = [$cache->load('key', $generator), $cache->load('key', $generator)];

		self::assertSame([false, false], $results);
		self::assertSame(1, $calls);
	}


	#[Test]
	public function fileDependencyInvalidatesItemWhenFileChanges(): void
	{
		$file = tempnam(sys_get_temp_dir(), 'neatous-redis-');
		self::assertIsString($file);

		try {
			file_put_contents($file, 'v1');
			touch($file, time() - 10);
			clearstatcache();

			$cache = new Cache($this->storage, 'app');
			$cache->save('key', 'value', [Cache::Files => $file]);
			self::assertSame('value', $cache->load('key'));

			file_put_contents($file, 'v2');
			touch($file, time());
			clearstatcache();

			self::assertNull($cache->load('key'));
		} finally {
			@unlink($file); // @ the test may have failed before the file was created
		}
	}














	#[Test]
	public function cleanAllOnNamespacedCacheCleansItAndDerivedOnly(): void
	{
		$this->requireCaching4();

		$parent = new Cache($this->storage, 'parent');
		$child = $parent->derive('child');
		$other = new Cache($this->storage, 'other');
		$parent->save('key', 'parent');
		$child->save('key', 'child');
		$other->save('key', 'other');

		// nette/caching 4 turns clean(all) of a namespaced cache into a Cache::Namespaces condition
		$parent->clean(all: true);

		self::assertNull($parent->load('key'));
		self::assertNull($child->load('key'));
		self::assertSame('other', $other->load('key'));
	}


	private function requireCaching4(): void
	{
		if (class_exists(\Nette\Caching\Dependencies::class)) {
			return;
		}

		self::markTestSkipped('Requires nette/caching 4.');
	}


	private function findCacheItemKey(): string
	{
		$found = [];
		foreach (KeyScanner::scan($this->redis, self::PREFIX . 'cache2:i:*') as $keys) {
			array_push($found, ...$keys);
		}

		self::assertCount(1, $found, 'Exactly one cache item expected in Redis.');

		return $found[0];
	}
}
