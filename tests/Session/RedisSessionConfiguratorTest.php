<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Session;

use Netous\Redis\Client\RedisConfig;
use Netous\Redis\Session\RedisSessionConfigurator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisSessionConfiguratorTest extends TestCase
{
	#[Test]
	public function configureSetsSaveHandler(): void
	{
		$config = new RedisConfig();

		RedisSessionConfigurator::configure($config);

		self::assertSame('redis', ini_get('session.save_handler'));
	}

	#[Test]
	public function configureSetsSavePathWithDefaults(): void
	{
		$config = new RedisConfig();

		RedisSessionConfigurator::configure($config);

		$savePath = (string) ini_get('session.save_path');
		self::assertStringContainsString('tcp://127.0.0.1:6379', $savePath);
		self::assertStringContainsString('prefix=app%3Asession%3A', $savePath);
		self::assertStringNotContainsString('auth=', $savePath);
		self::assertStringNotContainsString('database=', $savePath);
	}

	#[Test]
	public function configureIncludesAuthWhenPasswordSet(): void
	{
		$config = new RedisConfig(password: 'secret');

		RedisSessionConfigurator::configure($config);

		$savePath = (string) ini_get('session.save_path');
		self::assertStringContainsString('auth=secret', $savePath);
	}

	#[Test]
	public function configureIncludesDatabaseWhenNonZero(): void
	{
		$config = new RedisConfig(database: 5);

		RedisSessionConfigurator::configure($config);

		$savePath = (string) ini_get('session.save_path');
		self::assertStringContainsString('database=5', $savePath);
	}

	#[Test]
	public function configureWithCustomHostAndPort(): void
	{
		$config = new RedisConfig(host: '10.0.0.1', port: 6380);

		RedisSessionConfigurator::configure($config);

		$savePath = (string) ini_get('session.save_path');
		self::assertStringContainsString('tcp://10.0.0.1:6380', $savePath);
	}
}
