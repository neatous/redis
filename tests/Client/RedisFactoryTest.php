<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Client;

use Netous\Redis\Client\RedisConfig;
use Netous\Redis\Client\RedisFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real factory against a live Redis.
 */
final class RedisFactoryTest extends TestCase
{
	private const int DATABASE = 15;


	protected function setUp(): void
	{
		if (!extension_loaded('redis')) {
			self::markTestSkipped('ext-redis is not loaded.');
		}

		try {
			(new \Redis())->connect('127.0.0.1', 6379, 1.0);
		} catch (\RedisException) {
			self::markTestSkipped('Cannot connect to Redis at 127.0.0.1:6379.');
		}
	}


	protected function tearDown(): void
	{
		$redis = new \Redis();
		$redis->connect('127.0.0.1', 6379, 1.0);
		$redis->select(self::DATABASE);
		$redis->flushDb();
	}


	#[Test]
	public function createSelectsDatabaseAndEnablesSerializer(): void
	{
		$redis = (new RedisFactory())->create(new RedisConfig(database: self::DATABASE, timeout: 1.0));

		// the name is spelled out: the constant does not exist when phpredis is built without igbinary support
		$igbinary = defined('Redis::SERIALIZER_IGBINARY');
		self::assertSame(
			$igbinary ? constant('Redis::SERIALIZER_IGBINARY') : \Redis::SERIALIZER_PHP,
			$redis->getOption(\Redis::OPT_SERIALIZER),
		);

		// CI states which build it expects, so that a job cannot silently test the other serializer
		$expected = getenv('REDIS_TEST_EXPECT_IGBINARY');
		if ($expected !== false) {
			self::assertSame($expected === '1', $igbinary, 'Unexpected igbinary support in phpredis.');
		}

		$redis->set('key', ['structured' => true]);
		self::assertSame(['structured' => true], $redis->get('key'));

		$plain = new \Redis();
		$plain->connect('127.0.0.1', 6379, 1.0);
		$plain->select(self::DATABASE);
		self::assertIsString($plain->get('key'), 'The value must be in the selected database, serialized.');
	}


	#[Test]
	public function createWithPersistentConnection(): void
	{
		$factory = new RedisFactory();
		$persistent = new RedisConfig(database: self::DATABASE, timeout: 1.0, persistent: true);
		$regular = new RedisConfig(database: self::DATABASE, timeout: 1.0);

		// persistent connections are reused by the next client in the same process, regular ones are not
		self::assertSame(
			$factory->create($persistent)->rawCommand('CLIENT', 'ID'),
			$factory->create($persistent)->rawCommand('CLIENT', 'ID'),
		);
		self::assertNotSame(
			$factory->create($regular)->rawCommand('CLIENT', 'ID'),
			$factory->create($regular)->rawCommand('CLIENT', 'ID'),
		);
	}


	#[Test]
	public function persistentClientsOfDifferentDatabasesDoNotShareSocketWithoutPooling(): void
	{
		$pooling = ini_get('redis.pconnect.pooling_enabled');
		if ($pooling === false || ini_set('redis.pconnect.pooling_enabled', '0') === false) {
			self::markTestSkipped('redis.pconnect.pooling_enabled cannot be changed at runtime.');
		}

		try {
			$factory = new RedisFactory();
			$first = $factory->create(new RedisConfig(database: self::DATABASE, timeout: 1.0, persistent: true));
			$second = $factory->create(new RedisConfig(database: self::DATABASE - 1, timeout: 1.0, persistent: true));

			$second->flushDb();
			$first->set('isolation', 'first');

			self::assertNotSame($first->rawCommand('CLIENT', 'ID'), $second->rawCommand('CLIENT', 'ID'));
			self::assertFalse($second->get('isolation'));
			self::assertSame('first', $first->get('isolation'));
		} finally {
			ini_set('redis.pconnect.pooling_enabled', $pooling);
			if (isset($second)) {
				$second->flushDb();
			}
		}
	}


	#[Test]
	public function createFailsWhenDatabaseCannotBeSelected(): void
	{
		// phpredis only returns false for a rejected SELECT; silently staying on DB 0 must not happen
		$this->expectException(\RedisException::class);
		$this->expectExceptionMessageMatches('~database 100000~');

		(new RedisFactory())->create(new RedisConfig(database: 100000, timeout: 1.0));
	}


	#[Test]
	public function createSendsPassword(): void
	{
		// the test server has no password, so a sent AUTH fails; an ignored password would not
		$this->expectException(\RedisException::class);
		$this->expectExceptionMessageMatches('~AUTH~');

		(new RedisFactory())->create(new RedisConfig(password: 'secret', database: self::DATABASE, timeout: 1.0));
	}
}
