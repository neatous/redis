<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

use Netous\Redis\Cache\RedisKeyBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisKeyBuilderTest extends TestCase
{
	#[Test]
	public function buildProducesExpectedFormat(): void
	{
		$builder = new RedisKeyBuilder('app:');

		$key = $builder->build('myNamespace', 1, 'someKey');

		self::assertSame('app:cache:myNamespace:1:' . sha1('someKey'), $key);
	}

	#[Test]
	public function buildWithDifferentVersions(): void
	{
		$builder = new RedisKeyBuilder('app:');

		$v1 = $builder->build('ns', 1, 'key');
		$v2 = $builder->build('ns', 2, 'key');

		self::assertNotSame($v1, $v2);
	}

	#[Test]
	public function buildWithEmptyNamespace(): void
	{
		$builder = new RedisKeyBuilder('app:');

		$key = $builder->build('', 1, 'key');

		self::assertSame('app:cache::1:' . sha1('key'), $key);
	}

	#[Test]
	public function buildVersionKeyFormat(): void
	{
		$builder = new RedisKeyBuilder('app:');

		$key = $builder->buildVersionKey('myNamespace');

		self::assertSame('app:cache:ns:myNamespace', $key);
	}

	#[Test]
	public function differentPrefixesProduceDifferentKeys(): void
	{
		$builder1 = new RedisKeyBuilder('app1:');
		$builder2 = new RedisKeyBuilder('app2:');

		self::assertNotSame(
			$builder1->build('ns', 1, 'key'),
			$builder2->build('ns', 1, 'key'),
		);
	}

	#[Test]
	public function hashIsDeterministic(): void
	{
		$builder = new RedisKeyBuilder('app:');

		$key1 = $builder->build('ns', 1, 'sameKey');
		$key2 = $builder->build('ns', 1, 'sameKey');

		self::assertSame($key1, $key2);
	}
}
