<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

use Netous\Redis\Client\Serializer;
use PHPUnit\Framework\Assert;

/**
 * Lets the suite run with another serializer than the one RedisFactory picks (igbinary when phpredis supports it),
 * so that the PHP serializer path is tested too: REDIS_TEST_SERIALIZER=php vendor/bin/phpunit
 */
final class TestSerializer
{
	public static function apply(\Redis $redis): \Redis
	{
		$serializer = match (getenv('REDIS_TEST_SERIALIZER')) {
			'php' => \Redis::SERIALIZER_PHP,
			'igbinary' => Serializer::igbinary()
				?? Assert::markTestSkipped('phpredis is built without igbinary support.'),
			default => null,
		};

		if ($serializer !== null) {
			$redis->setOption(\Redis::OPT_SERIALIZER, $serializer);
		}

		return $redis;
	}
}
