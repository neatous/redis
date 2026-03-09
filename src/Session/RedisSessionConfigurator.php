<?php

declare(strict_types=1);

namespace Netous\Redis\Session;

use Netous\Redis\Client\RedisConfig;

final class RedisSessionConfigurator
{
	public static function configure(RedisConfig $config): void
	{
		$params = [];

		if ($config->password !== null) {
			$params['auth'] = $config->password;
		}

		if ($config->database !== 0) {
			$params['database'] = (string) $config->database;
		}

		$params['prefix'] = $config->prefix . 'session:';

		$query = http_build_query($params);
		$savePath = "tcp://{$config->host}:{$config->port}?{$query}";

		ini_set('session.save_handler', 'redis');
		ini_set('session.save_path', $savePath);
	}
}
