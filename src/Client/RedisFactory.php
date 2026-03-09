<?php

declare(strict_types=1);

namespace Netous\Redis\Client;

class RedisFactory
{
	public function create(RedisConfig $config): \Redis
	{
		$redis = new \Redis();

		if ($config->persistent) {
			$redis->pconnect($config->host, $config->port, $config->timeout);
		} else {
			$redis->connect($config->host, $config->port, $config->timeout);
		}

		if ($config->password !== null) {
			$redis->auth($config->password);
		}

		$redis->select($config->database);

		$serializer = extension_loaded('igbinary')
			? \Redis::SERIALIZER_IGBINARY
			: \Redis::SERIALIZER_PHP;
		$redis->setOption(\Redis::OPT_SERIALIZER, $serializer);

		return $redis;
	}
}
