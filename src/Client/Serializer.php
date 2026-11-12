<?php

declare(strict_types=1);

namespace Netous\Redis\Client;

/**
 * @internal
 */
final class Serializer
{
	/**
	 * Returns Redis::SERIALIZER_IGBINARY, or null when phpredis was built without igbinary support.
	 * The constant exists only in such a build; a loaded igbinary extension says nothing about phpredis.
	 */
	public static function igbinary(): ?int
	{
		return defined('Redis::SERIALIZER_IGBINARY') ? \Redis::SERIALIZER_IGBINARY : null;
	}


	/**
	 * Whether the value of Redis::OPT_SERIALIZER is a serializer that restores PHP objects.
	 */
	public static function restoresObjects(mixed $serializer): bool
	{
		return is_int($serializer) && in_array($serializer, [\Redis::SERIALIZER_PHP, self::igbinary()], true);
	}
}
