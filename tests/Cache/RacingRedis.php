<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

/**
 * Redis client that runs a callback once, right after the first execution of the chosen command (a raw command such
 * as GET or MGET, or UNLINK), i.e. inside the window between two steps of an operation. The callback may also throw
 * to simulate a failure at that point.
 */
final class RacingRedis extends \Redis
{
	private ?string $hookCommand = null;

	private ?\Closure $hook = null;

	private ?string $failCommand = null;


	public static function connectTo(int $database, \Redis $like): self
	{
		$redis = new self();
		$redis->connect('127.0.0.1', 6379, 1.0);
		$redis->select($database);
		$redis->setOption(\Redis::OPT_SERIALIZER, $like->getOption(\Redis::OPT_SERIALIZER));

		return $redis;
	}


	public function after(string $command, \Closure $hook): void
	{
		$this->hookCommand = strtoupper($command);
		$this->hook = $hook;
	}


	/**
	 * Makes the next raw command of this name reply with false, as phpredis does for an error reply.
	 */
	public function failNext(string $command): void
	{
		$this->failCommand = strtoupper($command);
	}


	public function hookFired(): bool
	{
		return $this->hook === null;
	}


	public function rawCommand(string $command, mixed ...$args): mixed
	{
		if ($this->failCommand === strtoupper($command)) {
			$this->failCommand = null;
			return false;
		}

		$result = parent::rawCommand($command, ...$args);
		$this->fire(strtoupper($command));

		return $result;
	}


	/** @param array<string>|string $key */
	public function unlink(array|string $key, string ...$other_keys): \Redis|int|false
	{
		$result = parent::unlink($key, ...$other_keys);
		$this->fire('UNLINK');

		return $result;
	}


	private function fire(string $command): void
	{
		$hook = $this->hook;
		if ($hook === null || $command !== $this->hookCommand) {
			return;
		}

		$this->hook = null;
		$hook();
	}
}
