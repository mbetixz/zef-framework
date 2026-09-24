<?php

declare(strict_types=1);

// ZEF Framework v2.23.0 — Configuration System v2 (issue #60 P3): pattern
// query memoization — first call computes, repeated patterns hit the memo,
// copy-on-write keeps cached series safe from caller mutation, and a fresh
// Config instance means a fresh cache (structural invalidation).

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\PatternQueryCache;

/**
 * @internal
 */
final class ConfigV2PatternQueryCacheTest extends TestCase
{
    public function testFirstCallMissesAndRepeatsHit(): void
    {
        $cache = new PatternQueryCache(new Config($this->values()));

        $first = $cache->query('database.connections.*.host');
        self::assertSame(['database.connections.mysql.host' => 'db.internal', 'database.connections.pg.host' => 'pg.internal'], $first);
        self::assertSame(['queries' => 1, 'hits' => 0, 'misses' => 1, 'patterns' => 1], $cache->stats());

        $second = $cache->query('database.connections.*.host');
        self::assertSame($first, $second);
        self::assertSame(['queries' => 2, 'hits' => 1, 'misses' => 1, 'patterns' => 1], $cache->stats());
    }

    public function testDistinctPatternsAreCachedSeparately(): void
    {
        $cache = new PatternQueryCache(new Config($this->values()));
        $cache->query('database.connections.*.host');
        $cache->query('database.connections.*.port');
        $cache->query('cache.*');

        self::assertSame(3, $cache->stats()['patterns']);
        self::assertSame(3, $cache->stats()['misses']);
        self::assertSame(0, $cache->stats()['hits']);

        self::assertSame(
            ['database.connections.mysql.port' => 3306, 'database.connections.pg.port' => 5432],
            $cache->query('database.connections.*.port'),
        );
        self::assertSame(1, $cache->stats()['hits']);
    }

    public function testResultsMatchDirectConfigQuery(): void
    {
        $config = new Config($this->values());
        $cache = new PatternQueryCache($config);

        self::assertSame($config->query('database.connections.mysql.*'), $cache->query('database.connections.mysql.*'));
        self::assertSame($config->query('*'), $cache->query('*'));
    }

    public function testCallerMutationCannotPoisonTheMemo(): void
    {
        $cache = new PatternQueryCache(new Config($this->values()));

        $result = $cache->query('database.connections.*.host');
        $result['database.connections.mysql.host'] = 'tampered';
        unset($result['database.connections.pg.host']);

        self::assertSame(
            ['database.connections.mysql.host' => 'db.internal', 'database.connections.pg.host' => 'pg.internal'],
            $cache->query('database.connections.*.host'),
            'copy-on-write must keep the cached series pristine',
        );
    }

    public function testClearDropsMemoAndCounters(): void
    {
        $cache = new PatternQueryCache(new Config($this->values()));
        $cache->query('cache.*');
        $cache->query('cache.*');

        $cache->clear();

        self::assertSame(['queries' => 0, 'hits' => 0, 'misses' => 0, 'patterns' => 0], $cache->stats());
        self::assertSame(['cache.driver' => 'redis', 'cache.ttl' => 300], $cache->query('cache.*'));
        self::assertSame(1, $cache->stats()['misses']);
    }

    public function testNewConfigInstanceMeansFreshCache(): void
    {
        $configA = new Config($this->values());
        $cacheA = new PatternQueryCache($configA);
        self::assertSame('redis', $cacheA->query('cache.*')['cache.driver'] ?? null);

        $valuesB = $this->values();
        $valuesB['cache'] = ['driver' => 'apcu', 'ttl' => 300];
        $configB = new Config($valuesB);
        self::assertSame('apcu', $configB->query('cache.*')['cache.driver']);

        // The old cache still answers for ITS immutable bag — by design.
        self::assertSame('redis', $cacheA->query('cache.*')['cache.driver'] ?? null);
    }

    public function testInvalidPatternValidationPassesThrough(): void
    {
        $cache = new PatternQueryCache(new Config($this->values()));

        $this->expectException(\InvalidArgumentException::class);
        $cache->query('');
    }

    /** @return array<string,mixed> */
    private function values(): array
    {
        return [
            'database' => [
                'connections' => [
                    'mysql' => ['host' => 'db.internal', 'port' => 3306],
                    'pg' => ['host' => 'pg.internal', 'port' => 5432],
                ],
            ],
            'cache' => ['driver' => 'redis', 'ttl' => 300],
        ];
    }
}
