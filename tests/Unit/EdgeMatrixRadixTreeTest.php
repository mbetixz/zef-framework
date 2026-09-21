<?php

declare(strict_types=1);

/*
 * ZEF Framework — Edge-Case Matrix tier 2 (v2.14.2): NamespaceRadixTree.
 * Adversarial scenarios: compression boundaries, mid-edge prefix queries,
 * nearest-ancestor scope resolution, and AOT export/restore round-trips.
 * Evidence: 56 escaped mutants in build/escapes-dom-rest.txt.
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\NamespaceRadixTree;

/**
 * @internal
 */
final class EdgeMatrixRadixTreeTest extends TestCase
{
    public function testInsertRejectsSealedEmptyAndDuplicateIds(): void
    {
        $tree = new NamespaceRadixTree();

        try {
            $tree->insert('');
            self::fail('empty service ID must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Service ID must be a non-empty string.', $e->getMessage());
        }
        $tree->insert('A\B\Svc');
        $tree->insert('A\B\Svc');
        self::assertSame(1, $tree->stats()['serviceIds'], 'duplicate inserts are idempotent');

        $tree->seal();

        try {
            $tree->insert('A\B\Other');
            self::fail('insert after seal must be rejected.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('sealed; insert() is not allowed', $e->getMessage());
        }
    }

    public function testAnnotateValidatesScopeAndNormalizesPrefix(): void
    {
        $tree = new NamespaceRadixTree();

        try {
            $tree->annotate('App', 'global');
            self::fail('unknown scope must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Unknown namespace scope 'global'", $e->getMessage());
            self::assertStringContainsString('expected one of: public, internal, module', $e->getMessage());
        }

        try {
            $tree->annotate('', 'public');
            self::fail('empty prefix must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Namespace prefix must be a non-empty string.', $e->getMessage());
        }
        $tree->annotate('App', 'internal');
        $tree->annotate('App\Sub\\', 'module');
        self::assertSame(['App\\' => 'internal', 'App\Sub\\' => 'module'], $tree->annotations(), 'prefixes normalize to a trailing separator');

        $tree->seal();

        try {
            $tree->annotate('Other', 'public');
            self::fail('annotate after seal must be rejected.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('sealed; annotate() is not allowed', $e->getMessage());
        }
    }

    public function testSealIsIdempotentAndCompressesSingleChildChains(): void
    {
        $tree = new NamespaceRadixTree();
        $tree->insert('Zef\Framework\Container\Svc');
        $before = $tree->stats();
        self::assertSame(5, $before['nodes'], 'root + four uncompressed segments');
        self::assertSame(4, $before['edges']);
        self::assertSame(1.0, $before['compressionRatio'], 'no compression before sealing');

        $tree->seal();
        $tree->seal();
        self::assertTrue($tree->isSealed());
        $after = $tree->stats();
        self::assertSame(2, $after['nodes'], 'chain collapses into a single compound edge');
        self::assertSame(1, $after['edges']);
        self::assertSame(4.0, $after['compressionRatio'], 'four raw segments over one edge');
        self::assertTrue($tree->containsExact('Zef\Framework\Container\Svc'), 'ids survive compression');
    }

    public function testBranchingNodesAreNeverCompressedAway(): void
    {
        $tree = new NamespaceRadixTree();
        $tree->insert('A\B\X');
        $tree->insert('A\B\Y');
        $tree->insert('A\C\Z');
        $tree->seal();
        self::assertTrue($tree->containsExact('A\B\X'));
        self::assertTrue($tree->containsExact('A\B\Y'));
        self::assertTrue($tree->containsExact('A\C\Z'));
        self::assertFalse($tree->containsExact('A\B'));
        self::assertFalse($tree->containsExact('A\B\X\Y'));
        $stats = $tree->stats();
        self::assertSame(3, $stats['serviceIds']);
        self::assertSame(3, $stats['maxDepth']);
        self::assertSame(9, $stats['rawSegments']);
    }

    public function testContainsExactHandlesCompoundEdgesAndMidEdgeQueries(): void
    {
        $tree = new NamespaceRadixTree();
        $tree->insert('Vendor\Long\Path\Service');
        $tree->insert('Vendor\Other\S');
        $tree->seal();

        self::assertTrue($tree->containsExact('Vendor\Long\Path\Service'));
        // Query ending mid-edge can never be an exact id.
        self::assertFalse($tree->containsExact('Vendor\Long\Path'));
        self::assertFalse($tree->containsExact('Vendor\Long'));
        self::assertFalse($tree->containsExact('Vendor\L'));
        self::assertFalse($tree->containsExact('Vendor\Long\Pxth\Service'), 'label mismatch inside a compound edge');
        self::assertFalse($tree->containsExact(''));
        self::assertFalse($tree->containsExact('Totally\Unknown'));
    }

    public function testIdsUnderPrefixIncludesWholeEdgeSubtreeOnMidEdgeQuery(): void
    {
        $tree = new NamespaceRadixTree();
        $tree->insert('App\Http\ShowAction');
        $tree->insert('App\Http\Admin\DeleteAction');
        $tree->insert('App\Cli\MigrateCommand');
        $tree->seal();

        self::assertSame(
            ['App\Http\Admin\DeleteAction', 'App\Http\ShowAction'],
            $tree->idsUnderPrefix('App\Http'),
            'exact-segment prefix collects the full subtree, sorted',
        );
        self::assertSame(
            ['App\Http\Admin\DeleteAction', 'App\Http\ShowAction'],
            $tree->idsUnderPrefix('App\Http\\'),
            'trailing separator is normalized',
        );
        self::assertSame(
            ['App\Http\Admin\DeleteAction'],
            $tree->idsUnderPrefix('App\Http\Admin'),
            'mid-edge prefix inside the compound edge owns its subtree',
        );
        self::assertSame([], $tree->idsUnderPrefix('App\Ht'), 'a head that matches no child key yields nothing');
        self::assertSame([], $tree->idsUnderPrefix('App\Http\Adminx'), 'mid-edge label mismatch yields nothing');
        self::assertSame([], $tree->idsUnderPrefix('Missing'), 'unknown root segment yields nothing');
        self::assertSame(
            ['App\Cli\MigrateCommand', 'App\Http\Admin\DeleteAction', 'App\Http\ShowAction'],
            $tree->idsUnderPrefix('App'),
        );
    }

    public function testIdsUnderPrefixRejectsEmptyPrefix(): void
    {
        $tree = new NamespaceRadixTree();

        try {
            $tree->idsUnderPrefix('');
            self::fail('empty prefix must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Namespace prefix must be a non-empty string.', $e->getMessage());
        }
    }

    public function testScopeOfResolvesNearestAncestorAnnotation(): void
    {
        $tree = new NamespaceRadixTree();
        $tree->insert('Zef\Sub\Deep\Svc');
        $tree->annotate('Zef', 'internal');
        $tree->annotate('Zef\Sub\Deep', 'module');
        $tree->seal();

        self::assertSame(['prefix' => 'Zef\Sub\Deep\\', 'scope' => 'module'], $tree->scopeOf('Zef\Sub\Deep\Svc'), 'longest prefix wins');
        self::assertSame(['prefix' => 'Zef\\', 'scope' => 'internal'], $tree->scopeOf('Zef\Sub\Other'));
        self::assertNull($tree->scopeOf('Zefish'), 'trailing separator stops byte-prefix bleed into sibling names');
        self::assertNull($tree->scopeOf('Other\Thing'), 'unannotated ids have no scope');
        self::assertSame(
            ['Zef\\' => 'internal', 'Zef\Sub\Deep\\' => 'module'],
            $tree->annotations(),
            'annotations are ksorted after sealing',
        );
    }

    public function testEmptyTreeStatsAndQueries(): void
    {
        $tree = new NamespaceRadixTree();
        $stats = $tree->stats();
        self::assertSame(0, $stats['serviceIds']);
        self::assertSame(1, $stats['nodes'], 'only the root exists');
        self::assertSame(0, $stats['edges']);
        self::assertSame(1.0, $stats['compressionRatio'], 'degenerate ratio for an edgeless tree is exactly 1.0');
        self::assertSame(0, $stats['maxDepth']);
        self::assertSame(0, $stats['annotations']);
        self::assertFalse($stats['sealed']);
        self::assertSame([], $tree->idsUnderPrefix('Anything'));
        self::assertNull($tree->scopeOf('Anything'));
    }

    public function testExportRestoreRoundTripPreservesBehaviorAndRejectsBrokenPayloads(): void
    {
        $tree = new NamespaceRadixTree();
        $tree->insert('X\Y\Svc');
        $tree->annotate('X', 'module');
        $tree->seal();
        $export = $tree->exportArray();
        self::assertSame('v2.11.0', $export['version']);
        self::assertTrue($export['sealed']);
        self::assertSame(1, $export['serviceCount']);

        $restored = NamespaceRadixTree::fromArray($export);
        self::assertTrue($restored->isSealed());
        self::assertTrue($restored->containsExact('X\Y\Svc'));
        self::assertSame(['X\Y\Svc'], $restored->idsUnderPrefix('X'));
        $scope = $restored->scopeOf('X\Y\Svc');
        self::assertNotNull($scope);
        self::assertSame('module', $scope['scope']);
        self::assertSame(3.0, $restored->stats()['compressionRatio'], 'restored raw segments and edges survive');

        try {
            NamespaceRadixTree::fromArray(['annotations' => []]);
            self::fail('payload without a root node must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Invalid NamespaceRadixTree payload: missing root node.', $e->getMessage());
        }
        // Missing optional keys default sanely (unsealed empty tree with root).
        $minimal = NamespaceRadixTree::fromArray(['root' => ['ids' => [], 'children' => []]]);
        self::assertTrue($minimal->isSealed(), 'sealed flag defaults to true on restore');
        self::assertSame(0, $minimal->stats()['serviceIds']);
        self::assertSame([], $minimal->annotations());
    }
}
