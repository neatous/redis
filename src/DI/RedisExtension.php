<?php

declare(strict_types=1);

namespace Netous\Redis\DI;

use Netous\Redis\Cache\RedisJournal;
use Netous\Redis\Cache\RedisKeyBuilder;
use Netous\Redis\Cache\RedisStorage;
use Netous\Redis\Client\RedisConfig;
use Netous\Redis\Client\RedisFactory;
use Netous\Redis\Session\RedisSessionConfigurator;
use Netous\Redis\Tracy\RedisPanel;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class RedisExtension extends CompilerExtension
{
	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'host' => Expect::string('127.0.0.1'),
			'port' => Expect::int(6379),
			'password' => Expect::string()->nullable(),
			'database' => Expect::int(0),
			'timeout' => Expect::float(1.5),
			'persistent' => Expect::bool(false),
			'prefix' => Expect::string('app:'),
			'cache' => Expect::bool(false),
			'journal' => Expect::bool(true),
			'sessions' => Expect::bool(false),
			'cacheDatabase' => Expect::int()->nullable(),
			'sessionDatabase' => Expect::int()->nullable(),
			'debug' => Expect::bool(false),
		]);
	}

	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var object{host: string, port: int, password: ?string, database: int, timeout: float, persistent: bool, prefix: string, cache: bool, journal: bool, sessions: bool, cacheDatabase: ?int, sessionDatabase: ?int} $config */
		$config = $this->config;

		$configDef = $builder->addDefinition($this->prefix('config'))
			->setFactory(RedisConfig::class, [
				$config->host,
				$config->port,
				$config->password,
				$config->database,
				$config->timeout,
				$config->persistent,
				$config->prefix,
			]);

		$builder->addDefinition($this->prefix('factory'))
			->setFactory(RedisFactory::class);

		$builder->addDefinition($this->prefix('client'))
			->setFactory(new Statement([
				new Statement(RedisFactory::class),
				'create',
			], [$configDef]));

		if ($config->cache) {
			$builder->addDefinition($this->prefix('cacheConfig'))
				->setFactory(RedisConfig::class, [
					$config->host,
					$config->port,
					$config->password,
					$config->cacheDatabase ?? $config->database,
					$config->timeout,
					$config->persistent,
					$config->prefix,
				])
				->setAutowired(false);

			$builder->addDefinition($this->prefix('cacheClient'))
				->setFactory(new Statement([
					new Statement(RedisFactory::class),
					'create',
				], [$this->prefix('@cacheConfig')]))
				->setAutowired(false);

			$builder->addDefinition($this->prefix('keyBuilder'))
				->setFactory(RedisKeyBuilder::class, [$config->prefix]);

			if ($config->journal) {
				$builder->addDefinition($this->prefix('journal'))
					->setFactory(RedisJournal::class, [$this->prefix('@cacheClient'), $config->prefix]);
			}
		}

		if (!$config->sessions) {
			return;
		}

		$builder->addDefinition($this->prefix('sessionConfig'))
			->setFactory(RedisConfig::class, [
				$config->host,
				$config->port,
				$config->password,
				$config->sessionDatabase ?? $config->database,
				$config->timeout,
				$config->persistent,
				$config->prefix,
			])
			->setAutowired(false);
	}

	public function beforeCompile(): void
	{
		/** @var object{host: string, port: int, password: ?string, database: int, timeout: float, persistent: bool, prefix: string, cache: bool, journal: bool, sessions: bool, cacheDatabase: ?int, sessionDatabase: ?int, debug: bool} $config */
		$config = $this->config;
		$builder = $this->getContainerBuilder();

		if ($config->cache) {
			$storageDef = $builder->hasDefinition('cache.storage')
				? $builder->getDefinition('cache.storage')
				: null;
			if ($storageDef instanceof \Nette\DI\Definitions\ServiceDefinition) {
				$storageDef->setFactory(RedisStorage::class, [
					$this->prefix('@cacheClient'),
					$this->prefix('@keyBuilder'),
					$config->journal ? $this->prefix('@journal') : null,
				]);
			}

			if ($config->journal && $builder->hasDefinition('cache.journal')) {
				$journalDef = $builder->getDefinition('cache.journal');
				if ($journalDef instanceof \Nette\DI\Definitions\ServiceDefinition) {
					$journalDef->setFactory(RedisJournal::class, [
						$this->prefix('@cacheClient'),
						$config->prefix,
					]);
				}

				$redisJournalDef = $builder->getDefinition($this->prefix('journal'));
				if ($redisJournalDef instanceof \Nette\DI\Definitions\ServiceDefinition) {
					$redisJournalDef->setAutowired(false);
				}
			}
		}

		if ($config->sessions && $builder->hasDefinition('session.session')) {
			$sessionDef = $builder->getDefinition('session.session');
			assert($sessionDef instanceof \Nette\DI\Definitions\ServiceDefinition);
			$sessionDef->addSetup([RedisSessionConfigurator::class, 'configure'], [$this->prefix('@sessionConfig')]);
		}

		if (!$config->debug || !$builder->hasDefinition('tracy.bar')) {
			return;
		}

		$panel = $builder->addDefinition($this->prefix('panel'))
			->setFactory(RedisPanel::class)
			->addSetup('addConnection', ['client', $this->prefix('@client'), $this->prefix('@config')]);

		if ($config->cache) {
			$panel->addSetup('addConnection', [
				'cache',
				$this->prefix('@cacheClient'),
				$this->prefix('@cacheConfig'),
			]);
		}

		if ($config->sessions) {
			$sessionClientDef = $builder->addDefinition($this->prefix('sessionClient'))
				->setFactory(new Statement([
					new Statement(RedisFactory::class),
					'create',
				], [$this->prefix('@sessionConfig')]))
				->setAutowired(false);

			$panel->addSetup('addConnection', ['session', $sessionClientDef, $this->prefix('@sessionConfig')]);
		}

		$tracyBarDef = $builder->getDefinition('tracy.bar');
		assert($tracyBarDef instanceof \Nette\DI\Definitions\ServiceDefinition);
		$tracyBarDef->addSetup('addPanel', [$panel]);
	}
}
