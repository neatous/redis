<?php

declare(strict_types=1);

namespace Netous\Redis\Client;

final readonly class RedisConfig
{
	public function __construct(
		public string $host = '127.0.0.1',
		public int $port = 6379,
		public ?string $password = null,
		public int $database = 0,
		public float $timeout = 1.5,
		public bool $persistent = false,
		public string $prefix = 'app:',
	) {
	}
}
