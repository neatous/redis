<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

use Netous\Redis\Cache\RedisJournal;
use Nette\Caching\Cache;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisJournalTest extends TestCase
{
	private \Redis $redis;
	private RedisJournal $journal;


	protected function setUp(): void
	{
		if (!extension_loaded('redis')) {
			self::markTestSkipped('ext-redis is not loaded.');
		}

		$this->redis = new \Redis();

		try {
			$this->redis->connect('127.0.0.1', 6379, 1.0);
		} catch (\RedisException) {
			self::markTestSkipped('Cannot connect to Redis at 127.0.0.1:6379.');
		}

		$this->redis->select(15);
		$this->redis->flushDb();

		$this->journal = new RedisJournal($this->redis, 'test:');
	}


	protected function tearDown(): void
	{
		if (!isset($this->redis) || !$this->redis->isConnected()) {
			return;
		}

		$this->redis->flushDb();
		$this->redis->close();
	}


	#[Test]
	public function writeAndCleanByTag(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['tag-a', 'tag-b']]);
		$this->journal->write('key2', [Cache::Tags => ['tag-a']]);
		$this->journal->write('key3', [Cache::Tags => ['tag-b']]);

		$removed = $this->journal->clean([Cache::Tags => ['tag-a']]);

		self::assertNotNull($removed);
		sort($removed);
		self::assertSame(['key1', 'key2'], $removed);
	}


	#[Test]
	public function cleanByTagDoesNotAffectOtherTags(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['tag-a']]);
		$this->journal->write('key2', [Cache::Tags => ['tag-b']]);

		$removed = $this->journal->clean([Cache::Tags => ['tag-a']]);

		self::assertSame(['key1'], $removed);

		$remaining = $this->journal->clean([Cache::Tags => ['tag-b']]);
		self::assertSame(['key2'], $remaining);
	}


	#[Test]
	public function writeAndCleanByPriority(): void
	{
		$this->journal->write('low', [Cache::Priority => 10]);
		$this->journal->write('medium', [Cache::Priority => 50]);
		$this->journal->write('high', [Cache::Priority => 100]);

		$removed = $this->journal->clean([Cache::Priority => 50]);

		self::assertNotNull($removed);
		sort($removed);
		self::assertSame(['low', 'medium'], $removed);
	}


	#[Test]
	public function cleanByTagsAndPriority(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['tag-a'], Cache::Priority => 10]);
		$this->journal->write('key2', [Cache::Tags => ['tag-b'], Cache::Priority => 100]);
		$this->journal->write('key3', [Cache::Priority => 5]);

		$removed = $this->journal->clean([Cache::Tags => ['tag-a'], Cache::Priority => 10]);

		self::assertNotNull($removed);
		sort($removed);
		self::assertSame(['key1', 'key3'], $removed);
	}


	#[Test]
	public function cleanAllReturnsNull(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['tag-a']]);
		$this->journal->write('key2', [Cache::Priority => 10]);

		$result = $this->journal->clean([Cache::All => true]);

		self::assertNull($result);

		$remaining = $this->journal->clean([Cache::Tags => ['tag-a']]);
		self::assertSame([], $remaining);
	}


	#[Test]
	public function writeOverwritesPreviousEntry(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['old-tag']]);
		$this->journal->write('key1', [Cache::Tags => ['new-tag']]);

		$removed = $this->journal->clean([Cache::Tags => ['old-tag']]);
		self::assertSame([], $removed);

		$removed = $this->journal->clean([Cache::Tags => ['new-tag']]);
		self::assertSame(['key1'], $removed);
	}


	#[Test]
	public function cleanWithNoMatchingTagsReturnsEmpty(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['tag-a']]);

		$removed = $this->journal->clean([Cache::Tags => ['nonexistent']]);
		self::assertSame([], $removed);
	}


	#[Test]
	public function cleanWithNoMatchingPriorityReturnsEmpty(): void
	{
		$this->journal->write('key1', [Cache::Priority => 100]);

		$removed = $this->journal->clean([Cache::Priority => 5]);
		self::assertSame([], $removed);
	}


	#[Test]
	public function writeWithNoDependenciesIsNoOp(): void
	{
		$this->journal->write('key1', []);

		$removed = $this->journal->clean([Cache::Tags => ['any']]);
		self::assertSame([], $removed);
	}


	#[Test]
	public function cleanedEntryIsRemovedFromAllStructures(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['tag-a', 'tag-b'], Cache::Priority => 10]);

		$this->journal->clean([Cache::Tags => ['tag-a']]);

		$byTagB = $this->journal->clean([Cache::Tags => ['tag-b']]);
		self::assertSame([], $byTagB);

		$byPriority = $this->journal->clean([Cache::Priority => 100]);
		self::assertSame([], $byPriority);
	}


	#[Test]
	public function duplicateTagsAreDeduped(): void
	{
		$this->journal->write('key1', [Cache::Tags => ['tag-a', 'tag-a', 'tag-a']]);

		$removed = $this->journal->clean([Cache::Tags => ['tag-a']]);
		self::assertSame(['key1'], $removed);
	}
}
