<?php

declare(strict_types=1);

namespace Netous\Redis\Tracy;

use Netous\Redis\Client\RedisConfig;
use Tracy\IBarPanel;

final class RedisPanel implements IBarPanel
{
	/** @var array<string, array{client: \Redis, config: RedisConfig}> */
	private array $connections = [];


	public function addConnection(string $name, \Redis $client, RedisConfig $config): void
	{
		$this->connections[$name] = [
			'client' => $client,
			'config' => $config,
		];
	}


	public function getTab(): string
	{
		$connected = true;
		foreach ($this->connections as $conn) {
			try {
				$conn['client']->ping();
			} catch (\RedisException) {
				$connected = false;
				break;
			}
		}

		$color = $connected ? '#d93a2e' : '#aaa';

		$d1 = 'M30.4 18.5c-2.1 1.1-13 5.6-15.3 6.8-2.3 1.2-3.6 1.2-5.4.3S2.5 21.8.5 20.6v3.8c0 0 6.5'
			. ' 3.5 9.2 4.8s3.1.9 5.4-.3 13.2-5.7 15.3-6.8v-3.6z';
		$d2 = 'M30.4 13.7c-2.1 1.1-13 5.6-15.3 6.8-2.3 1.2-3.6 1.2-5.4.3S2.5 17 .5 15.8v3.8c0 0 6.5'
			. ' 3.5 9.2 4.8s3.1.9 5.4-.3 13.2-5.7 15.3-6.8v-3.6z';
		$d3 = 'M30.4 9c-2.1 1.1-13 5.6-15.3 6.8-2.3 1.2-3.6 1.2-5.4.3S2.5 12.3.5 11.1l9.2-4.2c2.7'
			. '-1.3 3.1-.9 5.4.3S28.3 7.9 30.4 9z';

		return '<span title="Redis">'
			. '<svg viewBox="0 0 32 32" width="16" height="16">'
			. '<path fill="' . $color . '" d="' . $d1 . '"/>'
			. '<path fill="' . $color . '" d="' . $d2 . '"/>'
			. '<path fill="' . $color . '" d="' . $d3 . '"/>'
			. '</svg>'
			. '<span class="tracy-label">Redis</span></span>';
	}


	public function getPanel(): string
	{
		$connections = [];

		foreach ($this->connections as $name => $conn) {
			$client = $conn['client'];
			$config = $conn['config'];
			$info = [
				'name' => $name,
				'server' => $config->host . ':' . $config->port,
				'database' => $config->database,
				'prefix' => $config->prefix,
				'persistent' => $config->persistent,
			];

			try {
				$start = hrtime(true);
				$client->ping();
				$info['ping'] = round((hrtime(true) - $start) / 1_000_000, 2);
				$info['connected'] = true;
			} catch (\RedisException) {
				$info['ping'] = null;
				$info['connected'] = false;
			}

			try {
				$info['dbSize'] = $client->dbSize();
			} catch (\RedisException) {
				$info['dbSize'] = null;
			}

			try {
				$serverInfo = $client->info('memory');
				$info['memory'] = $serverInfo['used_memory_human'] ?? null;
			} catch (\RedisException) {
				$info['memory'] = null;
			}

			$connections[] = $info;
		}

		ob_start();
		require __DIR__ . '/templates/panel.phtml';
		return (string) ob_get_clean();
	}
}
