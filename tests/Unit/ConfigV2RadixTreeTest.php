<?php

declare(strict_types=1);

// ZEF Framework v2.21.1 — Configuration System v2 pattern queries: the
// ConfigRadixTree index and the Config::query()/subtree()/longestMatch()
// surface (wildcard match, subtree iteration, template resolution).

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigRadixTree;

/**
 * @internal
 */
final class ConfigV2RadixTreeTest extends TestCase
{
    // ---- ConfigRadixTree::match ---------------------------------------------

    public function testMatchFansOutOverWildcardSegment(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        self::assertSame(
            [
                'database.connections.mysql.host' => 'localhost',
                'database.connections.pgsql.host' => 'db.example.com',
            ],
            $tree->match('database.connections.*.host'),
        );
        self::assertSame(
            [
                'cache.file.driver' => 'filesystem',
                'cache.redis.driver' => 'predis',
            ],
            $tree->match('cache.*.driver'),
        );
    }

    public function testMatchExactPatternIsAnchored(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        self::assertSame(['database.password' => 's3cret'], $tree->match('database.password'));
        self::assertSame([], $tree->match('database.passwords'));
        self::assertSame(
            ['database' => $this->tree()['database']],
            $tree->match('database'),
            'an exact pattern may anchor at a subtree node and returns its array value',
        );
        self::assertSame([], $tree->match('database.connections.mysql.host.deeper'));
    }

    public function testMatchTopLevelStarIncludesSubtreesLeavesAndEmpty(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        $out = $tree->match('*');
        self::assertSame(
            ['cache', 'database', 'empty', 'flag', 'list', 'services'],
            array_keys($out),
        );
        self::assertTrue($out['flag']);
        self::assertSame([], $out['empty']);
        self::assertSame(['alpha', 'beta'], $out['list']);
    }

    public function testMatchSortsResultsRegardlessOfInsertionOrder(): void
    {
        $tree = new ConfigRadixTree([
            'zeta' => ['h' => 2],
            'alpha' => ['h' => 1],
        ]);
        self::assertSame(
            [
                'alpha.h' => 1,
                'zeta.h' => 2,
            ],
            $tree->match('*.h'),
            'match() must sort by path, not by insertion order',
        );
    }

    public function testMatchIteratesStoredWildcardSegmentsToo(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        self::assertSame(
            [
                'services.*.timeout' => 30,
                'services.auth.timeout' => 10,
            ],
            $tree->match('services.*.timeout'),
        );
    }

    public function testMatchRejectsEmptyPatternAndEmptySegments(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        foreach (['', 'a..b', '.a', 'a.'] as $pattern) {
            try {
                $tree->match($pattern);
                self::fail("Pattern '{$pattern}' must be rejected.");
            } catch (\InvalidArgumentException $e) {
                if ($pattern === '') {
                    self::assertSame('Config query pattern must not be empty.', $e->getMessage());
                } else {
                    self::assertSame(
                        "Config path '{$pattern}' contains an empty segment.",
                        $e->getMessage(),
                    );
                }
            }
        }
    }

    // ---- ConfigRadixTree::subtree -------------------------------------------

    public function testSubtreeReturnsRelativeLeavesSorted(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        self::assertSame(
            ['host' => 'localhost', 'port' => 3306],
            $tree->subtree('database.connections.mysql'),
        );
        self::assertSame(
            [
                'connections.mysql.host' => 'localhost',
                'connections.mysql.port' => 3306,
                'connections.pgsql.host' => 'db.example.com',
                'connections.pgsql.port' => 5432,
                'password' => 's3cret',
            ],
            $tree->subtree('database'),
        );
    }

    public function testSubtreeEdgeCases(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        self::assertSame([], $tree->subtree('missing.prefix'));
        self::assertSame([], $tree->subtree('database.password'));
        self::assertSame([], $tree->subtree('empty'));
        self::assertSame(['0' => 'alpha', '1' => 'beta'], $tree->subtree('list'));
        $minimal = new ConfigRadixTree(['a' => ['b' => 1], 'c' => true]);
        self::assertSame(['a.b' => 1, 'c' => true], $minimal->subtree(''), 'empty prefix = whole tree');
    }

    public function testSubtreeRejectsWildcardSegments(): void
    {
        $tree = new ConfigRadixTree($this->tree());

        try {
            $tree->subtree('database.*');
            self::fail('Wildcard subtree prefix must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                "Config subtree prefix 'database.*' must not contain wildcard segments.",
                $e->getMessage(),
            );
        }
    }

    // ---- ConfigRadixTree::longestMatch --------------------------------------

    public function testLongestMatchPrefersMostSpecificTemplate(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        self::assertSame(90, $tree->longestMatch('services.payment.stripe.timeout'));
        self::assertSame(60, $tree->longestMatch('services.payment.paypal.timeout'));
        self::assertSame(10, $tree->longestMatch('services.auth.timeout'));
        self::assertSame(30, $tree->longestMatch('services.unknown.timeout'));
        self::assertNull($tree->longestMatch('services.x.y.z'));
    }

    public function testLongestMatchFewestWildcardsWins(): void
    {
        $tree = new ConfigRadixTree([
            'svc' => [
                '*' => ['*' => ['v' => 1]],
                'a' => ['b' => ['v' => 2]],
            ],
        ]);
        self::assertSame(2, $tree->longestMatch('svc.a.b.v'));
        self::assertSame(1, $tree->longestMatch('svc.x.y.v'));
    }

    public function testLongestMatchTieBreaksLexicographically(): void
    {
        $tree = new ConfigRadixTree([
            'k' => [
                '*' => ['m' => 1],
                'm' => ['*' => 5],
            ],
        ]);
        // Query 'k.m.m' matches both 'k.m.*' (literal m, then * consumes m)
        // and 'k.*.m' (* consumes m, then literal m) — exactly one wildcard
        // each. '*' (0x2A) sorts before 'm' (0x6D), so 'k.*.m' must win.
        self::assertSame(1, $tree->longestMatch('k.m.m'));
    }

    public function testLongestMatchRejectsEmptyKey(): void
    {
        $tree = new ConfigRadixTree($this->tree());
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Config longest-match key must not be empty.');
        $tree->longestMatch('');
    }

    // ---- Config integration ---------------------------------------------------

    public function testConfigDelegatesPatternQueriesToIndex(): void
    {
        $config = new Config($this->tree());
        self::assertSame(
            [
                'database.connections.mysql.host' => 'localhost',
                'database.connections.pgsql.host' => 'db.example.com',
            ],
            $config->query('database.connections.*.host'),
        );
        self::assertSame(['host' => 'localhost', 'port' => 3306], $config->subtree('database.connections.mysql'));
        self::assertSame(60, $config->longestMatch('services.payment.paypal.timeout'));
        self::assertNull($config->longestMatch('nothing.here'));
    }

    public function testConfigExactAccessorsUnaffectedByIndex(): void
    {
        $config = new Config($this->tree());
        self::assertSame('localhost', $config->string('database.connections.mysql.host'));
        self::assertSame(3306, $config->int('database.connections.mysql.port'));
        self::assertTrue($config->bool('flag'));
        self::assertSame(['alpha', 'beta'], $config->array('list'));
        self::assertSame(
            [
                'cache.file.driver',
                'cache.redis.driver',
                'database.connections.mysql.host',
                'database.connections.mysql.port',
                'database.connections.pgsql.host',
                'database.connections.pgsql.port',
                'database.password',
                'empty',
                'flag',
                'list.0',
                'list.1',
                'services.*.timeout',
                'services.auth.timeout',
                'services.payment.*.timeout',
                'services.payment.stripe.timeout',
            ],
            $config->keys(),
        );
        self::assertSame([], $config->get('empty'));
    }

    public function testLoaderProducedBagCarriesIndex(): void
    {
        $bag = new ConfigLoader([new ConfigV2ArraySource(['a' => ['b' => 1]])])->load();
        self::assertSame(['a.b' => 1], $bag->query('a.*'));
    }

    /**
     * @return array<string,mixed>
     */
    private function tree(): array
    {
        return [
            'database' => [
                'connections' => [
                    'mysql' => ['host' => 'localhost', 'port' => 3306],
                    'pgsql' => ['host' => 'db.example.com', 'port' => 5432],
                ],
                'password' => 's3cret',
            ],
            'cache' => [
                'redis' => ['driver' => 'predis'],
                'file' => ['driver' => 'filesystem'],
            ],
            'services' => [
                '*' => ['timeout' => 30],
                'payment' => ['*' => ['timeout' => 60], 'stripe' => ['timeout' => 90]],
                'auth' => ['timeout' => 10],
            ],
            'list' => ['alpha', 'beta'],
            'empty' => [],
            'flag' => true,
        ];
    }
}
