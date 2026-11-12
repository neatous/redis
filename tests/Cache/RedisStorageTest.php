<?php

declare(strict_types=1);

namespace Netous\Redis\Tests\Cache;

use Netous\Redis\Cache\RedisJournal;
use Netous\Redis\Cache\RedisKeyBuilder;
use Netous\Redis\Cache\RedisStorage;
use Netous\Redis\Client\RedisConfig;
use Netous\Redis\Client\RedisFactory;
use Netous\Redis\Client\Serializer;
use Nette\Caching\Cache;
use Nette\Caching\Storages\Journal;
use Nette\InvalidArgumentException;
use Nette\InvalidStateException;
use Nette\NotSupportedException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Storage-level behavior against a live Redis, using the production client from RedisFactory (igbinary when phpredis
 * supports it).
 */
final class RedisStorageTest extends TestCase
{
	private const string PREFIX = 'test:';
	private const int DATABASE = 15;

	private static bool $callbackResult = true;

	private \Redis $redis;
	private RedisStorage $storage;
	private float $gcProbability;


	protected function setUp(): void
	{
		if (!extension_loaded('redis')) {
			self::markTestSkipped('ext-redis is not loaded.');
		}

		try {
			$this->redis = TestSerializer::apply((new RedisFactory())->create(
				new RedisConfig('127.0.0.1', 6379, null, self::DATABASE, 1.0, false, self::PREFIX),
			));
		} catch (\RedisException) {
			self::markTestSkipped('Cannot connect to Redis at 127.0.0.1:6379.');
		}

		$this->redis->flushDb();
		$this->gcProbability = RedisStorage::$gcProbability;
		RedisStorage::$gcProbability = 0;
		$this->storage = $this->createStorage();
	}


	protected function tearDown(): void
	{
		if (isset($this->gcProbability)) {
			RedisStorage::$gcProbability = $this->gcProbability;
		}

		if (!isset($this->redis) || !$this->redis->isConnected()) {
			return;
		}

		$this->redis->flushDb();
		$this->redis->close();
	}


	#[Test]
	public function valuesOfPreviousKeyFormatAreNeverRead(): void
	{
		// 1.0 format: {prefix}cache:{namespace}:{INCR version}:{sha1}, values stored bare
		$this->redis->rawCommand('SET', self::PREFIX . 'cache:ns:app', '2');
		$this->redis->set(self::PREFIX . 'cache:app:2:' . sha1('key'), ['d' => 'looks like an envelope', 'v' => 'x']);
		$this->redis->set(self::PREFIX . 'cache::1:' . sha1('root'), 'legacy-bare-value');

		self::assertNull($this->storage->read("app\x00key"));
		self::assertNull($this->storage->read("\x00root"));
	}


	#[Test]
	public function cleanAllRemovesKeysOfPreviousKeyFormat(): void
	{
		$this->redis->rawCommand('SET', self::PREFIX . 'cache:ns:app', '2');
		$this->redis->set(self::PREFIX . 'cache:app:2:' . sha1('key'), 'legacy');

		$this->storage->clean([Cache::All => true]);

		self::assertSame([], $this->allKeysSorted());
	}


	#[Test]
	public function writeUsesDefaultTtl(): void
	{
		$this->storage->write("app\x00key", 'value', []);

		self::assertSame(RedisStorage::DEFAULT_TTL, $this->ttlOfOnlyItem());
	}


	#[Test]
	public function nullDefaultTtlKeepsItemWithoutExpiration(): void
	{
		$this->createStorage(defaultTtl: null)->write("app\x00key", 'value', []);

		self::assertSame(-1, $this->ttlOfOnlyItem());
	}


	#[Test]
	public function writeUsesDateTimeExpire(): void
	{
		$this->storage->write("app\x00key", 'value', [Cache::Expire => new \DateTimeImmutable('+60 seconds')]);

		$ttl = $this->ttlOfOnlyItem();
		self::assertGreaterThanOrEqual(58, $ttl);
		self::assertLessThanOrEqual(60, $ttl);
	}


	#[Test]
	public function expireInThePastRemovesItem(): void
	{
		$this->storage->write("app\x00key", 'old', []);

		$this->storage->write("app\x00key", 'new', [Cache::Expire => 0]);

		self::assertNull($this->storage->read("app\x00key"));
	}


	#[Test]
	public function invalidExpireIsRejected(): void
	{
		$this->expectException(NotSupportedException::class);

		$this->storage->write("app\x00key", 'value', [Cache::Expire => 'not a time']);
	}


	#[Test]
	public function expireAsTimeStringIsAccepted(): void
	{
		$this->storage->write("app\x00key", 'value', [Cache::Expire => '20 minutes']);

		$ttl = $this->ttlOfOnlyItem();
		self::assertGreaterThan(1190, $ttl);
		self::assertLessThanOrEqual(1200, $ttl);
	}


	#[Test]
	public function slidingExpirationIsExtendedOnRead(): void
	{
		$this->storage->write("app\x00key", 'value', [Cache::Expire => 100, Cache::Sliding => true]);
		$this->redis->expire($this->onlyItemKey(), 5);

		self::assertSame('value', $this->storage->read("app\x00key"));
		self::assertSame(100, $this->ttlOfOnlyItem());
	}


	#[Test]
	public function failingCallbackInvalidatesItem(): void
	{
		self::$callbackResult = true;
		$this->storage->write("app\x00key", 'value', [Cache::Callbacks => [[[self::class, 'checkCallback']]]]);

		self::assertSame('value', $this->storage->read("app\x00key"));

		self::$callbackResult = false;
		self::assertNull($this->storage->read("app\x00key"));
		self::assertSame([], $this->allItemKeys());
	}


	#[Test]
	public function dependentItemIsInvalidatedWhenItemChanges(): void
	{
		$this->storage->write("app\x00base", 'base', []);
		$this->storage->write("app\x00dependent", 'dependent', [Cache::Items => ["app\x00base"]]);
		self::assertSame('dependent', $this->storage->read("app\x00dependent"));

		$this->storage->write("app\x00base", 'base-2', []);

		self::assertNull($this->storage->read("app\x00dependent"));
	}


	#[Test]
	public function dependentItemIsInvalidatedWhenItemIsRemoved(): void
	{
		$this->storage->write("app\x00base", 'base', []);
		$this->storage->write("app\x00dependent", 'dependent', [Cache::Items => ["app\x00base"]]);

		$this->storage->remove("app\x00base");

		self::assertNull($this->storage->read("app\x00dependent"));
	}


	#[Test]
	public function cleanAllKeepsForeignKeys(): void
	{
		$this->redis->set('PHPREDIS_SESSION:abc', 'session');
		$this->redis->set('other:key', 'foreign');
		$this->redis->set(self::PREFIX . 'cachefoo', 'own key of the application under the same prefix');
		$this->storage->write("app\x00key", 'value', [Cache::Tags => ['tag'], Cache::Priority => 1]);
		$this->storage->write("\x00root", 'value', []);

		$this->storage->clean([Cache::All => true]);

		self::assertNull($this->storage->read("app\x00key"));
		self::assertNull($this->storage->read("\x00root"));
		self::assertSame(['PHPREDIS_SESSION:abc', 'other:key', self::PREFIX . 'cachefoo'], $this->allKeysSorted());
	}


	#[Test]
	public function cleanAllFalseDoesNothing(): void
	{
		$this->storage->write("app\x00key", 'value', []);

		$this->storage->clean([Cache::All => false]);

		self::assertSame('value', $this->storage->read("app\x00key"));
	}


	#[Test]
	public function cleanNamespaceInvalidatesItAndDerivedNamespaces(): void
	{
		$this->storage->write("parent\x00key", 'parent', []);
		$this->storage->write("parent\x00child\x00key", 'child', []);
		$this->storage->write("other\x00key", 'other', []);

		$this->storage->clean([Cache::Namespaces => ['parent']]);

		self::assertNull($this->storage->read("parent\x00key"));
		self::assertNull($this->storage->read("parent\x00child\x00key"));
		self::assertSame('other', $this->storage->read("other\x00key"));
	}


	#[Test]
	public function cleanDerivedNamespaceKeepsParent(): void
	{
		$this->storage->write("parent\x00key", 'parent', []);
		$this->storage->write("parent\x00child\x00key", 'child', []);

		$this->storage->clean([Cache::Namespaces => ["parent\x00child"]]);

		self::assertSame('parent', $this->storage->read("parent\x00key"));
		self::assertNull($this->storage->read("parent\x00child\x00key"));
	}


	#[Test]
	public function repeatedNamespaceCleanKeepsInvalidating(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$this->storage->write("app\x00key", "value-$i", []);
			self::assertSame("value-$i", $this->storage->read("app\x00key"));

			$this->storage->clean([Cache::Namespaces => ['app']]);

			self::assertNull($this->storage->read("app\x00key"));
		}
	}


	#[Test]
	public function rewriteWithoutTagsDropsJournalEntry(): void
	{
		$this->storage->write("app\x00key", 'tagged', [Cache::Tags => ['tag']]);
		$this->storage->write("app\x00key", 'untagged', []);

		$this->storage->clean([Cache::Tags => ['tag']]);

		self::assertSame('untagged', $this->storage->read("app\x00key"));
	}


	#[Test]
	public function removeDropsJournalEntry(): void
	{
		$this->storage->write("app\x00key", 'value', [Cache::Tags => ['tag'], Cache::Priority => 5]);

		$this->storage->remove("app\x00key");

		self::assertSame([], $this->journalKeys());
	}


	#[Test]
	public function cleanByTagsAndNamespacesTogether(): void
	{
		$this->storage->write("a\x00key", 'a', [Cache::Tags => ['tag']]);
		$this->storage->write("b\x00key", 'b', []);
		$this->storage->write("c\x00key", 'c', []);

		$this->storage->clean([Cache::Tags => ['tag'], Cache::Namespaces => ['b']]);

		self::assertNull($this->storage->read("a\x00key"));
		self::assertNull($this->storage->read("b\x00key"));
		self::assertSame('c', $this->storage->read("c\x00key"));
	}


	#[Test]
	public function garbageCollectionDropsJournalEntriesOfMissingItems(): void
	{
		$this->storage->write("app\x00expired", 'value', [Cache::Tags => ['tag'], Cache::Priority => 1]);
		$this->storage->write("app\x00priorityOnly", 'value', [Cache::Priority => 2]);
		$this->storage->write("app\x00alive", 'value', [Cache::Tags => ['tag'], Cache::Priority => 3]);
		$this->storage->write("orphan\x00key", 'value', [Cache::Tags => ['other']]);

		// simulate expiration of two items and orphaning of the namespace
		foreach ($this->allItemKeys() as $key) {
			if (!str_contains($key, sha1('expired')) && !str_contains($key, sha1('priorityOnly'))) {
				continue;
			}

			$this->redis->del($key);
		}

		$this->storage->clean([Cache::Namespaces => ['orphan']]);

		$this->storage->clean([]);

		self::assertSame(["app\x00alive"], $this->redis->rawCommand('SMEMBERS', self::PREFIX . 'journal:tag:tag'));
		self::assertSame([], $this->redis->rawCommand('SMEMBERS', self::PREFIX . 'journal:tag:other'));
		$priorities = $this->redis->rawCommand('ZRANGE', self::PREFIX . 'journal:priority', '0', '-1');
		self::assertSame(["app\x00alive"], $priorities);
		self::assertSame('value', $this->storage->read("app\x00alive"));
	}


	#[Test]
	public function tagsAndPrioritiesWithoutJournalAreRejected(): void
	{
		// silently ignoring them would serve stale data; Nette's FileStorage throws as well
		$storage = new RedisStorage($this->redis, new RedisKeyBuilder(self::PREFIX));
		$storage->write("app\x00key", 'value', []);

		$operations = [
			static fn () => $storage->write("app\x00key", 'value', [Cache::Tags => ['tag']]),
			static fn () => $storage->write("app\x00key", 'value', [Cache::Priority => 1]),
			static fn () => $storage->clean([Cache::Tags => ['tag']]),
			static fn () => $storage->clean([Cache::Priority => 1]),
		];
		$storage->clean([Cache::Tags => []]); // nothing to clean, e.g. Cache::clean() with an empty tag list on 3.x

		foreach ($operations as $operation) {
			try {
				$operation();
				self::fail('InvalidStateException expected.');
			} catch (InvalidStateException) {
			}
		}

		self::assertSame('value', $storage->read("app\x00key"));
	}


	#[Test]
	public function garbageCollectionKeepsEntryOfItemRewrittenConcurrently(): void
	{
		// another process regenerates the expired item right after the GC resolved it as missing
		$this->assertGarbageCollectionKeepsConcurrentEntry(function (): void {
			$this->storage->write("app\x00hot", 'new', [Cache::Tags => ['tag']]);
		});
	}


	#[Test]
	public function garbageCollectionKeepsEntryOfItemWrittenUnderNewNamespaceVersion(): void
	{
		// the namespace is invalidated and the item written under the new version after the GC resolved the old one
		$this->assertGarbageCollectionKeepsConcurrentEntry(function (): void {
			$this->storage->clean([Cache::Namespaces => ['app']]);
			$this->storage->write("app\x00hot", 'new', [Cache::Tags => ['tag']]);
		});
	}


	#[Test]
	public function garbageCollectionRemovesItemsOrphanedByNamespaceInvalidation(): void
	{
		$this->storage->write("old\x00key", 'orphan', []);
		$this->storage->write("old\x00child\x00key", 'orphan', []);
		$this->storage->write("kept\x00key", 'kept', []);
		$this->storage->write("\x00root", 'root', []);
		$this->storage->clean([Cache::Namespaces => ['old']]);
		self::assertCount(4, $this->allItemKeys());

		$this->storage->clean([]);

		self::assertCount(2, $this->allItemKeys());
		self::assertSame('kept', $this->storage->read("kept\x00key"));
		self::assertSame('root', $this->storage->read("\x00root"));
	}


	#[Test]
	public function garbageCollectionRemovesItemsWhoseVersionKeyWasLost(): void
	{
		$this->storage->write("app\x00key", 'value', []);
		$this->redis->del(self::PREFIX . 'cache2:ns:app');

		$this->storage->clean([]);

		self::assertSame([], $this->allItemKeys());
	}


	#[Test]
	public function writesRunBoundedGarbageCollectionSteps(): void
	{
		$this->storage->write("old\x00key", 'orphan', [Cache::Tags => ['tag']]);
		$this->storage->clean([Cache::Namespaces => ['old']]);

		RedisStorage::$gcProbability = 1;
		$this->storage->write("app\x00key", 'value', []);

		self::assertSame([self::PREFIX . 'cache2:i:app'], array_map(
			static fn (string $key): string => substr($key, 0, strlen(self::PREFIX . 'cache2:i:app')),
			$this->allItemKeys(),
		));
		self::assertSame([], $this->redis->rawCommand('SMEMBERS', self::PREFIX . 'journal:tag:tag'));
	}


	#[Test]
	public function lostAncestorVersionInvalidatesDerivedNamespace(): void
	{
		$this->storage->write("parent\x00child\x00key", 'child', [Cache::Expire => 600]);

		$this->redis->del(self::PREFIX . 'cache2:ns:parent');

		self::assertNull($this->storage->read("parent\x00child\x00key"));
	}


	#[Test]
	public function dependentItemAcrossNamespaces(): void
	{
		$this->storage->write("a\x00base", 'base', []);
		$this->storage->write("b\x00dependent", 'dependent', [Cache::Items => ["a\x00base"]]);
		self::assertSame('dependent', $this->storage->read("b\x00dependent"));

		$this->storage->clean([Cache::Namespaces => ['a']]);

		self::assertNull($this->storage->read("b\x00dependent"));
	}


	#[Test]
	public function dependencyMissingAtWriteIsValidUntilItAppears(): void
	{
		$this->storage->write("app\x00dependent", 'dependent', [Cache::Items => ["app\x00missing"]]);
		self::assertSame('dependent', $this->storage->read("app\x00dependent"));

		$this->storage->write("app\x00missing", 'now exists', []);

		self::assertNull($this->storage->read("app\x00dependent"));
	}


	#[Test]
	public function zeroDefaultTtlIsRejected(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$this->createStorage(defaultTtl: 0);
	}


	#[Test]
	public function connectionWithoutSerializerIsRejected(): void
	{
		$redis = new \Redis();
		$redis->connect('127.0.0.1', 6379, 1.0);

		$this->expectException(InvalidArgumentException::class);

		new RedisStorage($redis, new RedisKeyBuilder(self::PREFIX));
	}


	#[Test]
	public function commandCountPerOperationStaysBounded(): void
	{
		$this->storage->write("app\x00key", 'value', []); // creates the version token

		self::assertSame(2, $this->countCommands(fn () => $this->storage->read("app\x00key")), 'read');
		// 2 round trips each: MGET versions, EVALSHA of the journal script; the server also counts the 5 commands
		// run inside the script (GET of the version token, SETEX/DEL, SMEMBERS, DEL, ZREM)
		self::assertSame(
			7,
			$this->countCommands(fn () => $this->storage->write("app\x00key", 'value', [])),
			'write without tags',
		);
		self::assertSame(
			7,
			$this->countCommands(fn () => $this->storage->remove("app\x00key")),
			'remove without tags',
		);
		self::assertSame(
			1, // MGET versions only: no token, no item
			$this->countCommands(fn () => $this->storage->read("missing\x00key")),
			'read in a namespace without version',
		);
	}


	#[Test]
	public function failedJournalScriptIsNotSilent(): void
	{
		// a corrupted entry (string instead of a set) makes the script fail with WRONGTYPE; the write must not be
		// reported as successful (as with OOM or any other error reply)
		$this->redis->rawCommand('SET', self::PREFIX . "journal:entry:app\x00key", 'not a set');

		$this->expectException(\RedisException::class);
		$this->expectExceptionMessageMatches('~WRONGTYPE~');

		$this->storage->write("app\x00key", 'value', [Cache::Tags => ['tag']]);
	}


	#[Test]
	public function journalOnAnotherConnectionIsRejected(): void
	{
		// items and entries must change atomically on one connection; a journal elsewhere would never be cleaned
		$other = (new RedisFactory())->create(
			new RedisConfig('127.0.0.1', 6379, null, self::DATABASE, 1.0, false, self::PREFIX),
		);

		$this->expectException(InvalidArgumentException::class);

		new RedisStorage($this->redis, new RedisKeyBuilder(self::PREFIX), new RedisJournal($other, self::PREFIX));
	}


	#[Test]
	public function connectionThatCannotRestoreObjectsIsRejected(): void
	{
		$redis = new \Redis();
		$redis->connect('127.0.0.1', 6379, 1.0);
		$redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_JSON);

		$this->expectException(InvalidArgumentException::class);

		new RedisStorage($redis, new RedisKeyBuilder(self::PREFIX));
	}


	#[Test]
	public function phpSerializerIsAcceptedInEveryBuild(): void
	{
		// must not depend on Redis::SERIALIZER_IGBINARY, which phpredis built without igbinary does not define
		$this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
		$storage = $this->createStorage();

		$storage->write("app\x00key", new \ArrayObject(['value']), []);

		self::assertEquals(new \ArrayObject(['value']), $storage->read("app\x00key"));
	}


	#[Test]
	public function itemWrittenWithAnotherSerializerIsMiss(): void
	{
		$igbinary = Serializer::igbinary() ?? self::markTestSkipped('phpredis is built without igbinary support.');

		$this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
		$this->createStorage()->write("app\x00key", ['value'], []);
		$this->redis->setOption(\Redis::OPT_SERIALIZER, $igbinary);
		self::assertNull($this->createStorage()->read("app\x00key"), 'PHP item read with igbinary');

		$this->createStorage()->write("app\x00key", ['value'], []);
		$this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
		self::assertNull($this->createStorage()->read("app\x00key"), 'igbinary item read with PHP');
	}


	#[Test]
	public function connectionWithKeyPrefixIsRejected(): void
	{
		$redis = (new RedisFactory())->create(new RedisConfig(database: self::DATABASE, timeout: 1.0));
		$redis->setOption(\Redis::OPT_PREFIX, 'other:');

		$this->expectException(InvalidArgumentException::class);

		new RedisStorage($redis, new RedisKeyBuilder(self::PREFIX));
	}


	#[Test]
	public function compressedConnectionWorks(): void
	{
		if (!defined('Redis::COMPRESSION_LZF')) {
			self::markTestSkipped('phpredis is built without LZF compression.');
		}

		$this->redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_LZF);
		$value = str_repeat('compressible ', 1000);

		$this->storage->write("app\x00tagged", $value, [Cache::Tags => ['tag']]); // through the journal script
		$plain = new RedisStorage($this->redis, new RedisKeyBuilder(self::PREFIX));
		$plain->write("app\x00plain", $value, []);

		self::assertSame($value, $this->storage->read("app\x00tagged"));
		self::assertSame($value, $plain->read("app\x00plain"));
		foreach ($this->allItemKeys() as $itemKey) {
			self::assertLessThan(1000, $this->redis->strlen($itemKey), 'The stored value must be compressed.');
		}
	}


	#[Test]
	public function otherJournalsAreUsedOnlyForTagsAndPriorities(): void
	{
		$journal = new class implements Journal {
			/** @var list<string> */
			public array $written = [];


			/** @param array<string, mixed> $_dependencies */
			public function write(string $key, array $_dependencies): void
			{
				$this->written[] = $key;
			}


			/**
			 * @param array<string, mixed> $_conditions
			 * @return list<string>
			 */
			public function clean(array $_conditions): array
			{
				return [];
			}
		};
		$storage = new RedisStorage($this->redis, new RedisKeyBuilder(self::PREFIX), $journal);

		$storage->write("app\x00plain", 'value', []);
		$storage->remove("app\x00plain");
		$storage->write("app\x00tagged", 'value', [Cache::Tags => ['tag']]);

		self::assertSame(["app\x00tagged"], $journal->written);
	}


	#[Test]
	public function cleanAllKeepsNoUntaggedItemWrittenConcurrently(): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		$this->storage->write("app\x00old", 'old', [Cache::Tags => ['tag']]);

		// a tagged item is written between the two steps of Cache::All (journal, then cache keys)
		$racing->after('UNLINK', function (): void {
			$this->storage->write("app\x00hot", 'new', [Cache::Tags => ['tag']]);
		});
		$racingStorage->clean([Cache::All => true]);
		self::assertTrue($racing->hookFired(), 'The race window was not reached.');
		$racing->close();

		$this->storage->clean([Cache::Tags => ['tag']]);

		$message = 'A tag clean must not miss the concurrently written item.';
		self::assertNull($this->storage->read("app\x00hot"), $message);
	}


	#[Test]
	public function interruptedTagCleanCanBeRetried(): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		$this->storage->write("app\x00key", 'value', [Cache::Tags => ['tag']]);

		$racing->after('MGET', static function (): never {
			throw new \RedisException('Connection lost.');
		});
		try {
			$racingStorage->clean([Cache::Tags => ['tag']]);
			self::fail('The simulated failure was not reached.');
		} catch (\RedisException) {
		}

		$racing->close();
		$this->storage->clean([Cache::Tags => ['tag']]);

		self::assertNull($this->storage->read("app\x00key"));
	}


	#[Test]
	public function slidingDoesNotShortenItemRewrittenConcurrently(): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		$this->storage->write("app\x00key", 'old', [Cache::Expire => 5, Cache::Sliding => true]);

		// rewritten without sliding after the read fetched the old value, before the sliding EXPIRE
		$racing->after('GET', function (): void {
			$this->storage->write("app\x00key", 'new', [Cache::Expire => 1000]);
		});
		$racingStorage->read("app\x00key");
		self::assertTrue($racing->hookFired(), 'The race window was not reached.');
		$racing->close();

		self::assertGreaterThan(900, $this->ttlOfOnlyItem());
	}


	#[Test]
	public function invalidItemRemovalKeepsItemRewrittenConcurrently(): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		self::$callbackResult = false;
		$this->storage->write("app\x00key", 'old', [Cache::Callbacks => [[[self::class, 'checkCallback']]]]);

		// a valid value is written after the read fetched the invalid one, before it is removed
		$racing->after('GET', function (): void {
			$this->storage->write("app\x00key", 'new', [Cache::Tags => ['tag']]);
		});
		self::assertNull($racingStorage->read("app\x00key"));
		self::assertTrue($racing->hookFired(), 'The race window was not reached.');
		$racing->close();

		self::assertSame('new', $this->storage->read("app\x00key"));
		$this->storage->clean([Cache::Tags => ['tag']]);
		self::assertNull($this->storage->read("app\x00key"), 'The kept item must keep its journal entry.');
	}


	#[Test]
	public function staleRemoveDoesNotUntagItemWrittenUnderNewNamespaceVersion(): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		$this->storage->write("app\x00key", 'old', []);

		// after the remover resolved the old version: the namespace is cleaned and the key written again with a tag
		$racing->after('MGET', function (): void {
			$this->storage->clean([Cache::Namespaces => ['app']]);
			$this->storage->write("app\x00key", 'new', [Cache::Tags => ['tag']]);
		});
		$racingStorage->remove("app\x00key");
		self::assertTrue($racing->hookFired(), 'The race window was not reached.');
		$racing->close();

		self::assertSame('new', $this->storage->read("app\x00key"));
		$this->storage->clean([Cache::Tags => ['tag']]);
		self::assertNull($this->storage->read("app\x00key"), 'The item written under the new version lost its tag.');
	}


	#[Test]
	public function staleWriteDoesNotReplaceEntryOfItemWrittenUnderNewNamespaceVersion(): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		$this->storage->write("app\x00key", 'old', []);

		$racing->after('MGET', function (): void {
			$this->storage->clean([Cache::Namespaces => ['app']]);
			$this->storage->write("app\x00key", 'new', [Cache::Tags => ['tag']]);
		});
		$racingStorage->write("app\x00key", 'stale', [Cache::Tags => ['other']]);
		self::assertTrue($racing->hookFired(), 'The race window was not reached.');
		$racing->close();

		$this->storage->clean([Cache::Tags => ['tag']]);
		self::assertNull($this->storage->read("app\x00key"), 'The item written under the new version lost its tag.');
	}


	#[Test]
	public function failedVersionReadDoesNotMakeGarbageCollectionDeleteLiveItems(): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		$this->storage->write("app\x00key", 'live', [Cache::Tags => ['tag']]);

		$racing->failNext('MGET'); // e.g. a timeout while GC reads the namespace versions
		try {
			$racingStorage->clean([]);
			self::fail('The failure must be reported.');
		} catch (\RedisException) {
		}

		$racing->close();
		self::assertSame('live', $this->storage->read("app\x00key"));
	}


	#[Test]
	public function tagCleanKeepsItemRewrittenWithoutTheTagMeanwhile(): void
	{
		$this->assertCleanKeepsItemRewrittenMeanwhile(
			[Cache::Tags => ['tag']],
			[Cache::Tags => ['other']],
			[Cache::Tags => ['tag']],
		);
	}


	#[Test]
	public function priorityCleanKeepsItemRewrittenWithHigherPriorityMeanwhile(): void
	{
		$this->assertCleanKeepsItemRewrittenMeanwhile(
			[Cache::Priority => 1],
			[Cache::Priority => 100],
			[Cache::Priority => 10],
		);
	}


	#[Test]
	public function priorityCleanRemovesItemsUpToThePriority(): void
	{
		foreach ([5, 10, 11] as $priority) {
			$this->storage->write("app\x00p$priority", $priority, [Cache::Priority => $priority]);
		}

		$this->storage->clean([Cache::Priority => 10]);

		self::assertNull($this->storage->read("app\x00p5"));
		self::assertNull($this->storage->read("app\x00p10"));
		self::assertSame(11, $this->storage->read("app\x00p11"));
	}


	#[Test]
	public function errorReplyInTagLookupIsNotSilent(): void
	{
		$this->storage->write("app\x00key", 'value', [Cache::Tags => ['tag']]);
		$this->redis->rawCommand('DEL', self::PREFIX . 'journal:tag:broken');
		$this->redis->rawCommand('SET', self::PREFIX . 'journal:tag:broken', 'not a set');

		$this->expectException(\RedisException::class);

		$this->storage->clean([Cache::Tags => ['broken']]);
	}


	#[Test]
	public function corruptedValueIsReadAsMissWithoutWarning(): void
	{
		$this->storage->write("app\x00key", 'value', []);
		$valid = $this->redis->_pack(['d' => str_repeat('x', 200)]);

		$truncated = substr($valid, 0, intdiv(strlen($valid), 2));
		$this->redis->rawCommand('SET', $this->onlyItemKey(), str_repeat('a', 16) . $truncated);

		self::assertNull($this->storage->read("app\x00key"));
	}


	#[Test]
	public function slidingWithoutExpireDoesNotExtendTtl(): void
	{
		// as with Nette's storages, sliding expiration needs Cache::Expire
		$this->storage->write("app\x00key", 'value', [Cache::Sliding => true]);
		$this->redis->expire($this->onlyItemKey(), 50);

		$this->storage->read("app\x00key");

		self::assertLessThanOrEqual(50, $this->ttlOfOnlyItem());
	}


	#[Test]
	public function readsRemovesAndTagCleansCreateNoKeys(): void
	{
		$this->storage->read("never\x00key");
		$this->storage->read("never\x00child\x00key");
		$this->storage->remove("never\x00key");
		$this->storage->clean([Cache::Namespaces => ['never']]);
		$this->storage->clean([Cache::Tags => ['none'], Cache::Priority => 1]);

		self::assertSame([], $this->allKeysSorted());
	}








	#[Test]
	public function dependentItemIsInvalidatedWhenItsDependencyBecomesInvalid(): void
	{
		self::$callbackResult = true;
		$this->storage->write("app\x00base", 'base', [Cache::Callbacks => [[[self::class, 'checkCallback']]]]);
		$this->storage->write("app\x00dependent", 'dependent', [Cache::Items => ["app\x00base"]]);
		self::assertSame('dependent', $this->storage->read("app\x00dependent"));

		self::$callbackResult = false; // the base item is now invalid although nobody has read it yet

		self::assertNull($this->storage->read("app\x00dependent"));
	}


	#[Test]
	public function cleaningRootNamespaceCleansEverything(): void
	{
		$this->storage->write("\x00root", 'root', []);
		$this->storage->write("app\x00key", 'value', []);

		$this->storage->clean([Cache::Namespaces => ['']]);

		self::assertNull($this->storage->read("\x00root"));
		self::assertNull($this->storage->read("app\x00key"));
	}


	/**
	 * @param \Closure(): void $concurrentWrite writes "app\x00hot" = 'new' with tag 'tag'
	 */
	private function assertGarbageCollectionKeepsConcurrentEntry(\Closure $concurrentWrite): void
	{
		[$racing, $gcStorage] = $this->createRacingStorage();

		$this->storage->write("app\x00hot", 'old', [Cache::Tags => ['tag']]);
		$this->redis->del($this->onlyItemKey()); // the item expires, its journal entry stays

		// the first MGET of the GC is resolving the entries' namespace versions (no items exist to scan before)
		$racing->after('MGET', $concurrentWrite);
		$gcStorage->clean([]);
		self::assertTrue($racing->hookFired(), 'The race window was not reached.');
		$racing->close();

		$this->storage->clean([Cache::Tags => ['tag']]);

		$message = 'A tag clean must not miss the concurrently written item.';
		self::assertNull($this->storage->read("app\x00hot"), $message);
	}


	/**
	 * @param array<string, mixed> $initial dependencies the item is written with first
	 * @param array<string, mixed> $rewritten dependencies of the concurrent rewrite (no longer matching the clean)
	 * @param array<string, mixed> $conditions clean conditions
	 */
	private function assertCleanKeepsItemRewrittenMeanwhile(array $initial, array $rewritten, array $conditions): void
	{
		[$racing, $racingStorage] = $this->createRacingStorage();
		$this->storage->write("app\x00key", 'old', $initial);

		// rewritten after the clean collected the matching keys, before it deletes them
		$racing->after('MGET', function () use ($rewritten): void {
			$this->storage->write("app\x00key", 'new', $rewritten);
		});
		$racingStorage->clean($conditions);
		self::assertTrue($racing->hookFired(), 'The race window was not reached.');
		$racing->close();

		self::assertSame('new', $this->storage->read("app\x00key"));
	}


	/**
	 * @return array{RacingRedis, RedisStorage}
	 */
	private function createRacingStorage(): array
	{
		$racing = RacingRedis::connectTo(self::DATABASE, $this->redis);

		$journal = new RedisJournal($racing, self::PREFIX);
		$storage = new RedisStorage($racing, new RedisKeyBuilder(self::PREFIX), $journal);

		return [$racing, $storage];
	}


	public static function checkCallback(): bool
	{
		return self::$callbackResult;
	}


	private function createStorage(?int $defaultTtl = RedisStorage::DEFAULT_TTL): RedisStorage
	{
		return new RedisStorage(
			$this->redis,
			new RedisKeyBuilder(self::PREFIX),
			new RedisJournal($this->redis, self::PREFIX),
			$defaultTtl,
		);
	}


	private function countCommands(\Closure $operation): int
	{
		$before = $this->totalCommands();
		$operation();

		return $this->totalCommands() - $before - 1; // minus the INFO call itself
	}


	private function totalCommands(): int
	{
		$info = $this->redis->info('stats');
		self::assertIsArray($info);
		self::assertIsNumeric($info['total_commands_processed'] ?? null);

		return (int) $info['total_commands_processed'];
	}


	/** @return list<string> */
	private function allKeysSorted(): array
	{
		$keys = $this->redis->keys('*');
		self::assertIsArray($keys);
		$keys = array_values(array_filter($keys, is_string(...)));
		sort($keys);

		return $keys;
	}


	/** @return list<string> item keys (without namespace version keys and journal) */
	private function allItemKeys(): array
	{
		return array_values(array_filter(
			$this->allKeysSorted(),
			static fn (string $key): bool => str_starts_with($key, self::PREFIX . 'cache2:i:'),
		));
	}


	/** @return list<string> */
	private function journalKeys(): array
	{
		return array_values(array_filter(
			$this->allKeysSorted(),
			static fn (string $key): bool => str_starts_with($key, self::PREFIX . 'journal:'),
		));
	}


	private function onlyItemKey(): string
	{
		$keys = $this->allItemKeys();
		self::assertCount(1, $keys, 'Exactly one cache item expected in Redis.');

		return $keys[0];
	}


	private function ttlOfOnlyItem(): int
	{
		$ttl = $this->redis->ttl($this->onlyItemKey());
		self::assertIsInt($ttl);

		return $ttl;
	}
}
