<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

/**
 * Lets the suite run with another serializer than the one RedisFactory picks (igbinary when loaded), so that the
 * PHP serializer path is tested too: REDIS_TEST_SERIALIZER=php vendor/bin/phpunit
 */
final class TestSerializer
{
	public static function apply(\Redis $redis): \Redis
	{
		$serializer = match (getenv('REDIS_TEST_SERIALIZER')) {
			'php' => \Redis::SERIALIZER_PHP,
			'igbinary' => \Redis::SERIALIZER_IGBINARY,
			default => null,
		};

		if ($serializer !== null) {
			$redis->setOption(\Redis::OPT_SERIALIZER, $serializer);
		}

		return $redis;
	}
}
