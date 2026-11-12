<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

/**
 * Runs Lua scripts on Redis.
 *
 * @internal
 */
final class LuaScript
{
	/**
	 * Runs the script by its SHA1, sending its text only when the server does not have it cached.
	 *
	 * @param list<string> $args keys followed by other arguments
	 * @throws \RedisException when the script fails (e.g. OOM), so a failed operation is never silent
	 */
	public static function run(\Redis $redis, string $script, array $args, int $numKeys): mixed
	{
		$redis->clearLastError();
		$result = $redis->evalSha(sha1($script), $args, $numKeys);
		if ($result !== false) {
			return $result;
		}

		if (str_starts_with((string) $redis->getLastError(), 'NOSCRIPT')) {
			$redis->clearLastError();
			$result = $redis->eval($script, $args, $numKeys);
			if ($result !== false) {
				return $result;
			}
		}

		throw new \RedisException('Redis script failed: ' . $redis->getLastError());
	}
}
