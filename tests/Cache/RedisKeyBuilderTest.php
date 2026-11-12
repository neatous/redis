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

		$key = $builder->build('myNamespace', ['abc'], 'someKey');

		self::assertSame('app:cache2:i:myNamespace:abc:' . sha1('someKey'), $key);
	}

	#[Test]
	public function buildJoinsVersionsOfAncestors(): void
	{
		$builder = new RedisKeyBuilder('app:');

		$key = $builder->build("parent\x00child", ['v1', 'v2'], 'key');

		self::assertSame("app:cache2:i:parent\x00child:v1.v2:" . sha1('key'), $key);
	}


	#[Test]
	public function buildVersionKeyFormat(): void
	{
		$builder = new RedisKeyBuilder('app:');

		$key = $builder->buildVersionKey('myNamespace');

		self::assertSame('app:cache2:ns:myNamespace', $key);
	}

	#[Test]
	public function cachePatternEscapesGlobCharacters(): void
	{
		self::assertSame('app:cache*', (new RedisKeyBuilder('app:'))->buildCacheRootsPattern());
		self::assertSame('a\*b\?\[c\]:cache*', (new RedisKeyBuilder('a*b?[c]:'))->buildCacheRootsPattern());
	}


	#[Test]
	public function isCacheKeyAcceptsOnlyCacheRoots(): void
	{
		$builder = new RedisKeyBuilder('app:');

		self::assertTrue($builder->isCacheKey('app:cache2:i:ns:v:' . sha1('x')));
		self::assertTrue($builder->isCacheKey('app:cache:ns:1:' . sha1('x')));
		self::assertFalse($builder->isCacheKey('app:cachefoo'));
		self::assertFalse($builder->isCacheKey('app:session:abc'));
	}


	#[Test]
	public function parseItemKeyReturnsNamespaceAndVersions(): void
	{
		$builder = new RedisKeyBuilder('app:');

		self::assertSame(
			["a:b\x00c", ['v1', 'v2']],
			$builder->parseItemKey($builder->build("a:b\x00c", ['v1', 'v2'], 'key')),
		);
		self::assertSame(['', []], $builder->parseItemKey($builder->build('', [], 'key')));
		self::assertNull($builder->parseItemKey('app:cache2:ns:foo'));
		self::assertNull($builder->parseItemKey('other:cache2:i:ns:v:' . sha1('x')));
	}
}
