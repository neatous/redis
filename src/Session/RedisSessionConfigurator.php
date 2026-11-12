<?php

declare(strict_types=1);

namespace Netous\Redis\Session;

use Netous\Redis\Client\RedisConfig;
use Nette\InvalidStateException;

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
		$params['timeout'] = (string) $config->timeout;

		if ($config->persistent) {
			$params['persistent'] = '1';
		}

		$host = str_contains($config->host, ':') && !str_starts_with($config->host, '[')
			? "[{$config->host}]" // IPv6 address
			: $config->host;
		$query = http_build_query($params, '', '&'); // independent of the arg_separator.output ini setting
		$savePath = "tcp://{$host}:{$config->port}?{$query}";

		if (ini_set('session.save_handler', 'redis') === false || ini_set('session.save_path', $savePath) === false) {
			throw new InvalidStateException(
				'Cannot configure Redis sessions: session.save_handler/save_path cannot be changed '
				. '(the session is probably already started, or the phpredis session handler is not available).',
			);
		}
	}
}
