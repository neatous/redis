<?php

declare(strict_types=1);

namespace Netous\Redis\Client;

class RedisFactory
{
	public function create(RedisConfig $config): \Redis
	{
		$redis = new \Redis();

		if ($config->persistent) {
			// a persistent ID per database keeps clients of different databases on separate sockets even when
			// phpredis' connection pooling (redis.pconnect.pooling_enabled) is disabled
			$redis->pconnect($config->host, $config->port, $config->timeout, 'db' . $config->database);
		} else {
			$redis->connect($config->host, $config->port, $config->timeout);
		}

		if ($config->password !== null && $redis->auth($config->password) !== true) {
			throw new \RedisException('Redis authentication failed: ' . $redis->getLastError());
		}

		if ($redis->select($config->database) !== true) {
			throw new \RedisException("Cannot select Redis database {$config->database}.");
		}

		$serializer = extension_loaded('igbinary')
			? \Redis::SERIALIZER_IGBINARY
			: \Redis::SERIALIZER_PHP;
		$redis->setOption(\Redis::OPT_SERIALIZER, $serializer);

		return $redis;
	}
}
