<?php

declare(strict_types=1);

// ZEF Framework v2.23.0 — Configuration System v2 (issue #60 P2): the
// OPcache-neutral radix index disk cache — round-trip fidelity, fingerprint
// and version invalidation, soft misses on corruption, atomic 0600 writes.

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigRadixTree;
use Zef\Framework\Config\RadixTreeCache;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\ZefVersion;

/**
 * @internal
 */
final class ConfigV2RadixCacheTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/zef-radixcache-' . uniqid();
        mkdir($this->workspace);
    }

    protected function tearDown(): void
    {
        $files = glob($this->workspace . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file); // nosemgrep: php.lang.security.unlink-use
            }
        }
        @rmdir($this->workspace);
    }

    // ---- round-trip fidelity ---------------------------------------------------

    public function testStoredIndexAnswersQueriesIdenticallyToAFreshBuild(): void
    {
        $values = self::sampleValues();
        $cache = new RadixTreeCache();
        $file = $this->workspace . '/radix.cache';

        $stored = $cache->store($values, $file);
        $fresh = new Config($values);

        self::assertSame($fresh->query('database.connections.*.host'), $stored->query('database.connections.*.host'));
        self::assertSame($fresh->subtree('database.connections.pg'), $stored->subtree('database.connections.pg'));
        self::assertSame($fresh->longestMatch('database.connections.mysql.port'), $stored->longestMatch('database.connections.mysql.port'));
        self::assertSame($fresh->all(), $stored->all());
    }

    public function testHydrateReusesCachedIndexOnSecondCall(): void
    {
        $values = self::sampleValues();
        $cache = new RadixTreeCache();
        $file = $this->workspace . '/radix.cache';

        $first = $cache->hydrate($values, $file); // miss -> build, no write
        self::assertFileDoesNotExist($file, 'hydrate() never writes');

        $cached = $cache->store($values, $file)->query('database.connections.*.host');
        $second = $cache->hydrate($values, $file); // hit -> cached index
        self::assertSame($cached, $second->query('database.connections.*.host'));
        self::assertSame($first->query('cache.*'), $second->query('cache.*'));
    }

    // ---- invalidation ----------------------------------------------------------

    public function testFingerprintMismatchInvalidates(): void
    {
        $file = $this->workspace . '/radix.cache';
        $cache = new RadixTreeCache();
        $cache->store(self::sampleValues(), $file);

        $changed = self::sampleValues();
        $changed['cache'] = ['driver' => 'redis', 'ttl' => 301];

        self::assertNull($cache->read($file, $changed));
        $rebuilt = $cache->hydrate($changed, $file);
        self::assertSame(301, $rebuilt->get('cache.ttl'));
    }

    public function testVersionStampMismatchInvalidates(): void
    {
        $file = $this->workspace . '/radix.cache';
        $values = self::sampleValues();
        // Hand-craft an envelope with a stale framework version.
        $entry = serialize([
            'version' => '0.0.0-not-this-release',
            'fingerprint' => hash('sha256', serialize($values)),
            'tree' => new ConfigRadixTree($values),
        ]);
        file_put_contents($file, $entry);

        self::assertNull(new RadixTreeCache()->read($file, $values));
    }

    public function testMissingTreeMemberInvalidates(): void
    {
        $file = $this->workspace . '/radix.cache';
        $values = self::sampleValues();
        file_put_contents($file, serialize([
            'version' => ZefVersion::VERSION,
            'fingerprint' => hash('sha256', serialize($values)),
            'tree' => 'not-a-tree',
        ]));

        self::assertNull(new RadixTreeCache()->read($file, $values));
    }

    // ---- soft misses -----------------------------------------------------------

    public function testCorruptFileIsASoftMiss(): void
    {
        $file = $this->workspace . '/radix.cache';
        file_put_contents($file, 'O:8:"stdClass":0:{}not-really-serializable');

        $cache = new RadixTreeCache();
        self::assertNull($cache->read($file, self::sampleValues()));

        $config = $cache->hydrate(self::sampleValues(), $file);
        self::assertSame('redis', $config->get('cache.driver'));
    }

    public function testForeignClassPayloadIsASoftMiss(): void
    {
        $file = $this->workspace . '/radix.cache';
        $values = self::sampleValues();
        file_put_contents($file, serialize([
            'version' => ZefVersion::VERSION,
            'fingerprint' => hash('sha256', serialize($values)),
            'tree' => new \ArrayObject([1, 2]),
        ]));

        self::assertNull(new RadixTreeCache()->read($file, $values));
    }

    public function testAbsentFileIsASoftMiss(): void
    {
        self::assertNull(new RadixTreeCache()->read($this->workspace . '/nope.cache', self::sampleValues()));
    }

    // ---- write guarantees ------------------------------------------------------

    public function testStoredFileCarriesRestrictivePermissionsAndNoTempResidue(): void
    {
        $file = $this->workspace . '/radix.cache';
        new RadixTreeCache()->store(self::sampleValues(), $file);

        self::assertFileExists($file);
        clearstatcache(true, $file);
        $mode = fileperms($file) & 0o777;
        self::assertSame(0o600, $mode);

        $residue = glob($this->workspace . '/.*.tmp');
        self::assertSame([], $residue, 'atomic publish must leave no temp files');
    }

    public function testCustomFileModeIsHonoured(): void
    {
        $file = $this->workspace . '/radix.cache';
        new RadixTreeCache(0o640)->store(self::sampleValues(), $file);

        clearstatcache(true, $file);
        self::assertSame(0o640, fileperms($file) & 0o777);
    }

    public function testInvalidFileModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RadixTreeCache(0o1000);
    }

    public function testStoreIntoMissingDirectoryFailsFast(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        new RadixTreeCache()->store(self::sampleValues(), $this->workspace . '/missing-dir/radix.cache');
    }

    public function testStoreIsIdempotentForIdenticalValues(): void
    {
        $file = $this->workspace . '/radix.cache';
        $cache = new RadixTreeCache();
        $first = $cache->store(self::sampleValues(), $file);
        $second = $cache->store(self::sampleValues(), $file);

        self::assertSame($first->query('database.connections.*'), $second->query('database.connections.*'));
        self::assertNotNull($cache->read($file, self::sampleValues()));
    }

    /** @return array<string,mixed> */
    private static function sampleValues(): array
    {
        return [
            'database' => [
                'connections' => [
                    'mysql' => ['host' => 'db.internal', 'port' => 3306],
                    'pg' => ['host' => 'pg.internal', 'port' => 5432],
                ],
            ],
            'cache' => ['driver' => 'redis', 'ttl' => 300],
            'services' => ['*.timeout' => 30],
        ];
    }
}
