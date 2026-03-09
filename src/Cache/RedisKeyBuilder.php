<?php

declare(strict_types=1);

namespace Netous\Redis\Cache;

final readonly class RedisKeyBuilder
{
	public function __construct(
		private string $prefix,
	) {
	}

	public function build(string $namespace, int $version, string $key): string
	{
		return $this->prefix . 'cache:' . $namespace . ':' . $version . ':' . sha1($key);
	}

	public function buildVersionKey(string $namespace): string
	{
		return $this->prefix . 'cache:ns:' . $namespace;
	}
}
