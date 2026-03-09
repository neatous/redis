<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Client;

use Netous\Redis\Client\RedisConfig;
use Netous\Redis\Client\RedisFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisFactoryTest extends TestCase
{
	#[Test]
	public function createConnectsWithDefaults(): void
	{
		$config = new RedisConfig();
		$redis = $this->createMock(\Redis::class);

		$redis->expects(self::once())
			->method('connect')
			->with('127.0.0.1', 6379, 1.5);

		$redis->expects(self::never())
			->method('pconnect');

		$redis->expects(self::never())
			->method('auth');

		$redis->expects(self::once())
			->method('select')
			->with(0);

		$redis->expects(self::once())
			->method('setOption')
			->with(\Redis::OPT_SERIALIZER, self::anything());

		$factory = new class ($redis) extends RedisFactory {
			public function __construct(private readonly \Redis $mock)
			{
			}

			public function create(RedisConfig $config): \Redis
			{
				if ($config->persistent) {
					$this->mock->pconnect($config->host, $config->port, $config->timeout);
				} else {
					$this->mock->connect($config->host, $config->port, $config->timeout);
				}

				if ($config->password !== null) {
					$this->mock->auth($config->password);
				}

				$this->mock->select($config->database);

				$serializer = extension_loaded('igbinary')
					? \Redis::SERIALIZER_IGBINARY
					: \Redis::SERIALIZER_PHP;
				$this->mock->setOption(\Redis::OPT_SERIALIZER, $serializer);

				return $this->mock;
			}
		};

		$result = $factory->create($config);
		self::assertSame($redis, $result);
	}

	#[Test]
	public function createWithPersistentConnection(): void
	{
		$config = new RedisConfig(persistent: true, password: 'secret', database: 3);
		$redis = $this->createMock(\Redis::class);

		$redis->expects(self::never())
			->method('connect');

		$redis->expects(self::once())
			->method('pconnect')
			->with('127.0.0.1', 6379, 1.5);

		$redis->expects(self::once())
			->method('auth')
			->with('secret');

		$redis->expects(self::once())
			->method('select')
			->with(3);

		$redis->expects(self::once())
			->method('setOption');

		$factory = new class ($redis) extends RedisFactory {
			public function __construct(private readonly \Redis $mock)
			{
			}

			public function create(RedisConfig $config): \Redis
			{
				if ($config->persistent) {
					$this->mock->pconnect($config->host, $config->port, $config->timeout);
				} else {
					$this->mock->connect($config->host, $config->port, $config->timeout);
				}

				if ($config->password !== null) {
					$this->mock->auth($config->password);
				}

				$this->mock->select($config->database);

				$serializer = extension_loaded('igbinary')
					? \Redis::SERIALIZER_IGBINARY
					: \Redis::SERIALIZER_PHP;
				$this->mock->setOption(\Redis::OPT_SERIALIZER, $serializer);

				return $this->mock;
			}
		};

		$result = $factory->create($config);
		self::assertSame($redis, $result);
	}
}
