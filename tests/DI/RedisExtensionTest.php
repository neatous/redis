<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\DI;

use Netous\Redis\Cache\RedisJournal;
use Netous\Redis\Cache\RedisStorage;
use Netous\Redis\Client\RedisConfig;
use Netous\Redis\DI\RedisExtension;
use Nette\Bridges\CacheDI\CacheExtension;
use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Nette\Caching\Storages\FileStorage;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\DI\ContainerLoader;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\InvalidConfigurationException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisExtensionTest extends TestCase
{
	private string $tempDir;

	protected function setUp(): void
	{
		$this->tempDir = sys_get_temp_dir() . '/netous-redis-test-' . uniqid();
		mkdir($this->tempDir, 0777, true);
	}

	protected function tearDown(): void
	{
		$this->removeDir($this->tempDir);
	}

	#[Test]
	public function registersBaseServices(): void
	{
		$container = $this->createContainer([]);

		self::assertTrue($container->hasService('redis.config'));
		self::assertTrue($container->hasService('redis.factory'));
		self::assertTrue($container->hasService('redis.client'));
	}

	#[Test]
	public function configServiceHasDefaults(): void
	{
		$container = $this->createContainer([]);
		$config = $container->getService('redis.config');

		self::assertInstanceOf(RedisConfig::class, $config);
		self::assertSame('127.0.0.1', $config->host);
		self::assertSame(6379, $config->port);
		self::assertNull($config->password);
		self::assertSame(0, $config->database);
		self::assertSame('app:', $config->prefix);
	}

	#[Test]
	public function configServiceWithCustomValues(): void
	{
		$container = $this->createContainer([
			'host' => '10.0.0.1',
			'port' => 6380,
			'password' => 'secret',
			'database' => 2,
			'timeout' => 3.0,
			'persistent' => true,
			'prefix' => 'myapp:',
		]);

		$config = $container->getService('redis.config');

		self::assertInstanceOf(RedisConfig::class, $config);
		self::assertSame('10.0.0.1', $config->host);
		self::assertSame(6380, $config->port);
		self::assertSame('secret', $config->password);
		self::assertSame(2, $config->database);
		self::assertSame(3.0, $config->timeout);
		self::assertTrue($config->persistent);
		self::assertSame('myapp:', $config->prefix);
	}


	#[Test]
	public function emptyPrefixIsRejectedByConfigSchema(): void
	{
		$this->expectException(InvalidConfigurationException::class);

		$this->compileWithCacheExtension(['cache' => true, 'prefix' => '']);
	}


	#[Test]
	public function integerTimeoutIsAccepted(): void
	{
		$config = $this->createContainer(['timeout' => 2])->getService('redis.config');

		self::assertInstanceOf(RedisConfig::class, $config);
		self::assertSame(2.0, $config->timeout);
	}


	#[Test]
	public function storageIsRegisteredWithoutNetteCacheExtension(): void
	{
		$compiler = new Compiler();
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => ['cache' => true]]);
		$compiler->compile();
		$def = $compiler->getContainerBuilder()->getDefinitionByType(Storage::class);

		self::assertInstanceOf(ServiceDefinition::class, $def);
		self::assertSame(RedisStorage::class, $def->getFactory()->getEntity());
	}


	#[Test]
	public function negativeDatabaseIsRejectedByConfigSchema(): void
	{
		$rejected = [];
		foreach (['database', 'cacheDatabase', 'sessionDatabase'] as $option) {
			try {
				$this->compileWithCacheExtension(['cache' => true, $option => -1]);
			} catch (InvalidConfigurationException) {
				$rejected[] = $option;
			}
		}

		self::assertSame(['database', 'cacheDatabase', 'sessionDatabase'], $rejected);
	}

	#[Test]
	public function cacheServicesRegisteredWhenEnabled(): void
	{
		$container = $this->createContainer(['cache' => true]);

		self::assertTrue($container->hasService('redis.cacheClient'));
		self::assertTrue($container->hasService('redis.keyBuilder'));
	}

	#[Test]
	public function cacheServicesNotRegisteredByDefault(): void
	{
		$container = $this->createContainer([]);

		self::assertFalse($container->hasService('redis.cacheClient'));
		self::assertFalse($container->hasService('redis.keyBuilder'));
	}

	#[Test]
	public function containerCreatesWorkingRedisCacheStorage(): void
	{
		if (!extension_loaded('redis')) {
			self::markTestSkipped('ext-redis is not loaded.');
		}

		try {
			(new \Redis())->connect('127.0.0.1', 6379, 1.0);
		} catch (\RedisException) {
			self::markTestSkipped('Cannot connect to Redis at 127.0.0.1:6379.');
		}

		$container = $this->createContainer(
			['cache' => true, 'cacheDatabase' => 15, 'prefix' => 'test-di:', 'timeout' => 1.0],
			withCacheExtension: true,
		);
		$storage = $container->getByType(Storage::class);
		self::assertInstanceOf(RedisStorage::class, $storage);

		$cache = new Cache($storage, 'di');
		$cache->save('key', ['value'], [Cache::Tags => ['tag']]);
		self::assertSame(['value'], $cache->load('key'));

		$cache->clean([Cache::Tags => ['tag']]);
		self::assertNull($cache->load('key'));

		$storage->clean([Cache::All => true]);
	}

	#[Test]
	public function cacheStorageReplacedWithRedisWhenCacheExtensionPresent(): void
	{
		$builder = $this->compileWithCacheExtension(['cache' => true]);
		$def = $builder->getDefinition('cache.storage');

		self::assertInstanceOf(ServiceDefinition::class, $def);
		self::assertSame(RedisStorage::class, $def->getFactory()->getEntity());
	}

	#[Test]
	public function defaultTtlIsPassedToStorage(): void
	{
		$default = $this->compileWithCacheExtension(['cache' => true])
			->getDefinition('cache.storage');
		$custom = $this->compileWithCacheExtension(['cache' => true, 'defaultTtl' => 60])
			->getDefinition('cache.storage');
		$infinite = $this->compileWithCacheExtension(['cache' => true, 'defaultTtl' => null])
			->getDefinition('cache.storage');

		self::assertInstanceOf(ServiceDefinition::class, $default);
		self::assertInstanceOf(ServiceDefinition::class, $custom);
		self::assertInstanceOf(ServiceDefinition::class, $infinite);
		self::assertSame(RedisStorage::DEFAULT_TTL, $default->getFactory()->arguments[3]);
		self::assertSame(60, $custom->getFactory()->arguments[3]);
		self::assertNull($infinite->getFactory()->arguments[3]);
	}

	#[Test]
	public function zeroDefaultTtlIsRejectedByConfigSchema(): void
	{
		$this->expectException(InvalidConfigurationException::class);

		$this->compileWithCacheExtension(['cache' => true, 'defaultTtl' => 0]);
	}

	#[Test]
	public function journalReplacedWithRedisWhenCacheExtensionPresent(): void
	{
		$builder = $this->compileWithCacheExtension(['cache' => true]);

		if ($builder->hasDefinition('cache.journal')) {
			$def = $builder->getDefinition('cache.journal');
			self::assertInstanceOf(ServiceDefinition::class, $def);
			self::assertSame(RedisJournal::class, $def->getFactory()->getEntity());
		} else {
			$def = $builder->getDefinition('redis.journal');
			self::assertInstanceOf(ServiceDefinition::class, $def);
			self::assertSame(RedisJournal::class, $def->getFactory()->getEntity());
		}
	}

	#[Test]
	public function cacheStorageNotReplacedWhenCacheDisabled(): void
	{
		$builder = $this->compileWithCacheExtension([]);
		$def = $builder->getDefinition('cache.storage');

		self::assertInstanceOf(ServiceDefinition::class, $def);
		self::assertSame(FileStorage::class, $def->getFactory()->getEntity());
	}

	#[Test]
	public function sessionConfigRegisteredWhenEnabled(): void
	{
		$container = $this->createContainer(['sessions' => true]);

		self::assertTrue($container->hasService('redis.sessionConfig'));
	}

	#[Test]
	public function sessionConfigNotRegisteredByDefault(): void
	{
		$container = $this->createContainer([]);

		self::assertFalse($container->hasService('redis.sessionConfig'));
	}


	#[Test]
	public function cacheDatabaseOverridesBaseDatabase(): void
	{
		$container = $this->createContainer(['cache' => true, 'cacheDatabase' => 3]);

		/** @var RedisConfig $cacheConfig */
		$cacheConfig = $container->getService('redis.cacheConfig');
		/** @var RedisConfig $baseConfig */
		$baseConfig = $container->getService('redis.config');

		self::assertSame(3, $cacheConfig->database);
		self::assertSame(0, $baseConfig->database);
	}

	#[Test]
	public function sessionDatabaseOverridesBaseDatabase(): void
	{
		$container = $this->createContainer(['sessions' => true, 'sessionDatabase' => 1]);

		/** @var RedisConfig $sessionConfig */
		$sessionConfig = $container->getService('redis.sessionConfig');
		/** @var RedisConfig $baseConfig */
		$baseConfig = $container->getService('redis.config');

		self::assertSame(1, $sessionConfig->database);
		self::assertSame(0, $baseConfig->database);
	}

	#[Test]
	public function journalRegisteredByDefaultWhenCacheEnabled(): void
	{
		$container = $this->createContainer(['cache' => true]);

		self::assertTrue($container->hasService('redis.journal'));
	}

	#[Test]
	public function journalNotRegisteredWhenDisabled(): void
	{
		$container = $this->createContainer(['cache' => true, 'journal' => false]);

		self::assertFalse($container->hasService('redis.journal'));
	}

	#[Test]
	public function journalNotRegisteredWhenCacheDisabled(): void
	{
		$container = $this->createContainer([]);

		self::assertFalse($container->hasService('redis.journal'));
	}

	#[Test]
	public function cacheDatabaseDefaultsToBaseDatabase(): void
	{
		$container = $this->createContainer(['cache' => true, 'database' => 2]);

		/** @var RedisConfig $cacheConfig */
		$cacheConfig = $container->getService('redis.cacheConfig');
		/** @var RedisConfig $baseConfig */
		$baseConfig = $container->getService('redis.config');

		self::assertSame($baseConfig->database, $cacheConfig->database);
	}

	#[Test]
	public function sessionDatabaseDefaultsToBaseDatabase(): void
	{
		$container = $this->createContainer(['sessions' => true, 'database' => 2]);

		/** @var RedisConfig $sessionConfig */
		$sessionConfig = $container->getService('redis.sessionConfig');
		/** @var RedisConfig $baseConfig */
		$baseConfig = $container->getService('redis.config');

		self::assertSame($baseConfig->database, $sessionConfig->database);
	}

	/** @param array<string, mixed> $config */
	private function createContainer(array $config, bool $withCacheExtension = false): Container
	{
		$loader = new ContainerLoader($this->tempDir, autoRebuild: true);
		$tempDir = $this->tempDir;

		$generator = static function (Compiler $compiler) use ($config, $withCacheExtension, $tempDir): ?string {
			if ($withCacheExtension) {
				$compiler->addExtension('cache', new CacheExtension($tempDir . '/cache'));
			}

			$compiler->addExtension('redis', new RedisExtension());
			$compiler->addConfig(['redis' => $config]);

			return null;
		};
		/** @var class-string<Container> $class */
		$class = $loader->load($generator, serialize([$config, $withCacheExtension]));

		return new $class();
	}

	/** @param array<string, mixed> $config */
	private function compileWithCacheExtension(array $config): \Nette\DI\ContainerBuilder
	{
		$compiler = new Compiler();
		$compiler->addExtension('cache', new CacheExtension($this->tempDir));
		$compiler->addExtension('redis', new RedisExtension());
		$compiler->addConfig(['redis' => $config]);
		$compiler->compile();

		return $compiler->getContainerBuilder();
	}

	private function removeDir(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}

		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);

		/** @var \SplFileInfo $item */
		foreach ($items as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}

		rmdir($dir);
	}
}
