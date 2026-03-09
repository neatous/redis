<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

use Netous\Redis\Cache\RedisKeyBuilder;
use Netous\Redis\Cache\RedisStorage;
use Nette\Caching\Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class RedisStorageTest extends TestCase
{
	private \Redis&MockObject $redis;
	private RedisKeyBuilder $keyBuilder;
	private RedisStorage $storage;

	protected function setUp(): void
	{
		$this->redis = $this->createMock(\Redis::class);
		$this->keyBuilder = new RedisKeyBuilder('app:');
		$this->storage = new RedisStorage($this->redis, $this->keyBuilder);
	}

	#[Test]
	public function readReturnsNullOnMiss(): void
	{
		$this->redis->method('get')->willReturn(false);

		self::assertNull($this->storage->read('myKey'));
	}

	#[Test]
	public function readReturnsValueOnHit(): void
	{
		$this->redis->method('get')->willReturn('cached-value');

		self::assertSame('cached-value', $this->storage->read('myKey'));
	}

	#[Test]
	public function readWithNamespace(): void
	{
		$namespace = "myNs";
		$name = "myKey";
		$key = $namespace . "\x00" . $name;

		// First get call: namespace version lookup -> returns "5"
		// Second get call: actual cache read -> returns "data"
		$this->redis->method('get')
			->willReturnCallback(static function (string $redisKey) {
				if (str_contains($redisKey, 'cache:ns:')) {
					return '5';
				}

				return 'data';
			});

		$result = $this->storage->read($key);

		self::assertSame('data', $result);
	}

	#[Test]
	public function writeUsesDefaultTtl(): void
	{
		$this->redis->expects(self::once())
			->method('setex')
			->with(
				self::anything(),
				3600,
				'value',
			);

		$this->storage->write('key', 'value', []);
	}

	#[Test]
	public function writeUsesCustomTtl(): void
	{
		$this->redis->expects(self::once())
			->method('setex')
			->with(
				self::anything(),
				120,
				'value',
			);

		$this->storage->write('key', 'value', [Cache::Expire => 120]);
	}

	#[Test]
	public function writeWithDateTimeExpiry(): void
	{
		$future = new \DateTimeImmutable('+60 seconds');

		$this->redis->expects(self::once())
			->method('setex')
			->with(
				self::anything(),
				self::callback(static fn(int $ttl) => $ttl >= 58 && $ttl <= 62),
				'value',
			);

		$this->storage->write('key', 'value', [Cache::Expire => $future]);
	}

	#[Test]
	public function writeEnforcesMinimumTtlOfOne(): void
	{
		$this->redis->expects(self::once())
			->method('setex')
			->with(
				self::anything(),
				1,
				'value',
			);

		$this->storage->write('key', 'value', [Cache::Expire => 0]);
	}

	#[Test]
	public function removeDeletesKey(): void
	{
		$this->redis->expects(self::once())
			->method('del')
			->with(self::stringContains('cache:'));

		$this->storage->remove('key');
	}

	#[Test]
	public function cleanAllFlushesDb(): void
	{
		$this->redis->expects(self::once())
			->method('flushDb');

		$this->redis->expects(self::never())
			->method('incr');

		$this->storage->clean([Cache::All => true]);
	}

	#[Test]
	public function cleanNamespaceIncrementsVersion(): void
	{
		$this->redis->expects(self::once())
			->method('incr')
			->with('app:cache:ns:myNamespace');

		$this->redis->expects(self::never())
			->method('flushDb');

		$this->storage->clean([Cache::Namespaces => ['myNamespace']]);
	}

	#[Test]
	public function cleanWithNoConditionsDoesNothing(): void
	{
		$this->redis->expects(self::never())->method('flushDb');
		$this->redis->expects(self::never())->method('incr');

		$this->storage->clean([]);
	}

	#[Test]
	public function lockIsNoOp(): void
	{
		$this->storage->lock('key');
		$this->expectNotToPerformAssertions();
	}

	#[Test]
	public function namespaceVersionDefaultsToOneWhenMissing(): void
	{
		$namespace = "ns";
		$name = "key";
		$key = $namespace . "\x00" . $name;

		$expectedRedisKey = $this->keyBuilder->build($namespace, 1, $name);

		$versionKey = $this->keyBuilder->buildVersionKey($namespace);

		$this->redis->method('get')
			->willReturnCallback(static function (string $redisKey) use ($expectedRedisKey, $versionKey) {
				if ($redisKey === $versionKey) {
					return false; // version key does not exist
				}

				self::assertSame($expectedRedisKey, $redisKey);

				return 'hit';
			});

		self::assertSame('hit', $this->storage->read($key));
	}

	#[Test]
	public function writeWithNamespaceUsesVersionedKey(): void
	{
		$namespace = "products";
		$name = "item-42";
		$key = $namespace . "\x00" . $name;

		$this->redis->method('get')
			->willReturnCallback(static function (string $redisKey) {
				if (str_contains($redisKey, 'cache:ns:')) {
					return '3';
				}

				return false;
			});

		$expectedRedisKey = $this->keyBuilder->build($namespace, 3, $name);

		$this->redis->expects(self::once())
			->method('setex')
			->with($expectedRedisKey, 3600, 'data');

		$this->storage->write($key, 'data', []);
	}
}
