<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Client;

use Netous\Redis\Client\RedisConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisConfigTest extends TestCase
{
	#[Test]
	public function defaultValues(): void
	{
		$config = new RedisConfig();

		self::assertSame('127.0.0.1', $config->host);
		self::assertSame(6379, $config->port);
		self::assertNull($config->password);
		self::assertSame(0, $config->database);
		self::assertSame(1.5, $config->timeout);
		self::assertFalse($config->persistent);
		self::assertSame('app:', $config->prefix);
	}

	#[Test]
	public function customValues(): void
	{
		$config = new RedisConfig(
			host: '10.0.0.1',
			port: 6380,
			password: 'secret',
			database: 2,
			timeout: 3.0,
			persistent: true,
			prefix: 'myapp:',
		);

		self::assertSame('10.0.0.1', $config->host);
		self::assertSame(6380, $config->port);
		self::assertSame('secret', $config->password);
		self::assertSame(2, $config->database);
		self::assertSame(3.0, $config->timeout);
		self::assertTrue($config->persistent);
		self::assertSame('myapp:', $config->prefix);
	}
}
