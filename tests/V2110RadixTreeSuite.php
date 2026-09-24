<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.11.0 radix-tree namespace suite (dedicated test file).
 *
 * Covers the RadixTree Namespace Container end to end:
 *   - NamespaceRadixTree structure: segment traversal, path compression, stats
 *   - Prefix queries: segment-boundary semantics, sorted determinism
 *   - NamespaceScopePolicy enforcement: internal deny, module budget, public default
 *   - Container integration: getByPrefix batch fetch, namespace fallbacks, PSR-11 has()
 *   - AOT: exportArray/fromArray var_export round-trip, zero-reflection payload
 *   - Lifecycle: freeze -> sealed tree, immutability guards
 *
 * Run standalone:  bin/zef --self-test=v211
 * Run everything:  bin/zef --self-test
 */

namespace Zef\Test;

use Psr\Container\ContainerInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\NamespaceRadixTree;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Policy\NamespaceScopePolicy;

final class V2110RadixTreeSuite
{
    public function __construct(private readonly CliRunner $runner) {}

    /** Entry point invoked by CliRunner::run(). */
    public function run(): void
    {
        $this->v211TreeStructure();
        $this->v211PrefixQueries();
        $this->v211ScopePolicy();
        $this->v211GetByPrefix();
        $this->v211Fallback();
        $this->v211Aot();
        $this->v211Lifecycle();
        $this->v211EdgeSemantics();
    }

    private function ok(bool $cond, string $label): void
    {
        $this->runner->ok($cond, $label);
    }

    private function throws(string $class, callable $fn, string $label): void
    {
        $this->runner->throws($class, $fn, $label);
    }

    // ------------------------------------------------------------------

    private function sampleTree(): NamespaceRadixTree
    {
        $tree = new NamespaceRadixTree();
        foreach (
            [
                'App\Domain\Users\CreateUser',
                'App\Domain\Users\DeleteUser',
                'App\Domain\Orders\PlaceOrder',
                Container::class,
                'Zef\Middleware\AuthMiddleware',
                'db.connection',
            ] as $id
        ) {
            $tree->insert($id);
        }
        $tree->annotate('Zef\Framework', NamespaceRadixTree::SCOPE_INTERNAL);
        $tree->annotate('App\ModuleB', NamespaceRadixTree::SCOPE_MODULE);
        $tree->seal();

        return $tree;
    }

    private function v211TreeStructure(): void
    {
        $tree = $this->sampleTree();
        $this->ok($tree->containsExact('App\Domain\Users\CreateUser'), 'tree: exact hit leaf');
        $this->ok($tree->containsExact(Container::class), 'tree: exact hit deep chain');
        $this->ok($tree->containsExact('db.connection'), 'tree: exact hit root leaf (dot id)');
        $this->ok(!$tree->containsExact('App\Domain\Users'), 'tree: namespace path is not a service');
        $this->ok(!$tree->containsExact('App\Domain\Users\CreateUsers'), 'tree: near-miss rejected');
        $this->ok(!$tree->containsExact(''), 'tree: empty id never matches');

        $stats = $tree->stats();
        $this->ok($stats['serviceIds'] === 6, 'stats: 6 service ids indexed');
        $this->ok($stats['maxDepth'] === 4, 'stats: max depth is 4 segments');
        $this->ok($stats['rawSegments'] === 20, 'stats: raw segment count 20');
        $this->ok($stats['nodes'] < $stats['rawSegments'], 'stats: path compression shrinks node count');
        $this->ok($stats['compressionRatio'] > 1.0, 'stats: compression ratio > 1.0');
        $this->ok($stats['sealed'] === true && $tree->isSealed(), 'stats: tree sealed');
        $this->ok($stats['annotations'] === 2, 'stats: two scope annotations');
    }

    private function v211PrefixQueries(): void
    {
        $tree = $this->sampleTree();

        $ids = $tree->idsUnderPrefix('App\Domain');
        $this->ok(
            $ids === ['App\Domain\Orders\PlaceOrder', 'App\Domain\Users\CreateUser', 'App\Domain\Users\DeleteUser'],
            'prefix: subtree sorted deterministically (no trailing separator needed)'
        );
        $this->ok($tree->idsUnderPrefix('App\\') === $ids, 'prefix: trailing-separator form matches too');
        $this->ok(
            $tree->idsUnderPrefix('App\Domain\Users') === ['App\Domain\Users\CreateUser', 'App\Domain\Users\DeleteUser'],
            'prefix: nested subtree narrows'
        );
        $this->ok(
            $tree->idsUnderPrefix('Zef') === [Container::class, 'Zef\Middleware\AuthMiddleware'],
            'prefix: sibling branches collected'
        );
        $this->ok($tree->idsUnderPrefix('db.connection') === ['db.connection'], 'prefix: exact root leaf id works');

        $this->ok($tree->idsUnderPrefix('App\Domain\Use') === [], 'prefix: partial segment never matches');
        $this->ok($tree->idsUnderPrefix('App\X') === [], 'prefix: unknown namespace is empty');
        $this->ok($tree->idsUnderPrefix('db') === [], 'prefix: prefix of a dotted leaf does not half-match');
        $this->throws(\InvalidArgumentException::class, fn (): array => $tree->idsUnderPrefix(''), 'prefix: empty prefix rejected');
    }

    private function v211ScopePolicy(): void
    {
        // internal deny: App consumer -> Zef\Framework target
        $c = new Container();
        $c->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['Zef\Framework' => NamespaceRadixTree::SCOPE_INTERNAL],
        ));
        $c->register('App\A', fn (): \stdClass => new \stdClass(), ['Zef\Framework\Database']);
        $c->register('Zef\Framework\Database', fn (): \stdClass => new \stdClass());
        $this->throws(
            ModuleDependencyViolationException::class,
            fn () => $c->validateAndFreeze(),
            'scope: internal target denied for outside consumer'
        );

        // same-subtree consumer is allowed
        $c2 = new Container();
        $c2->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['Zef\Framework' => NamespaceRadixTree::SCOPE_INTERNAL],
        ));
        $c2->register('Zef\Framework\Database', fn (): \stdClass => new \stdClass());
        $c2->register('Zef\Framework\Cache', fn (): \stdClass => new \stdClass(), ['Zef\Framework\Database']);
        $c2->validateAndFreeze();
        $this->ok($c2->get('Zef\Framework\Cache') instanceof \stdClass, 'scope: same-subtree consumer allowed');

        // module budget: 2 distinct targets into a module scope with budget 1
        $c3 = new Container();
        $c3->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['App\ModuleB' => NamespaceRadixTree::SCOPE_MODULE],
            maxCrossScopeRefs: 1,
        ));
        $c3->register('App\ModuleA\One', fn (): \stdClass => new \stdClass(), ['App\ModuleB\TargetB', 'App\ModuleB\OtherB']);
        $c3->register('App\ModuleB\TargetB', fn (): \stdClass => new \stdClass());
        $c3->register('App\ModuleB\OtherB', fn (): \stdClass => new \stdClass());
        $this->throws(
            ModuleDependencyViolationException::class,
            fn () => $c3->validateAndFreeze(),
            'scope: module budget exceeded on 2nd distinct target'
        );

        // module budget: 1 distinct target referenced twice is fine (edge dedup)
        $c4 = new Container();
        $c4->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['App\ModuleB' => NamespaceRadixTree::SCOPE_MODULE],
            maxCrossScopeRefs: 1,
        ));
        $c4->register('App\ModuleA\One', fn (): \stdClass => new \stdClass(), ['App\ModuleB\TargetB']);
        $c4->register('App\ModuleA\Two', fn (): \stdClass => new \stdClass(), ['App\ModuleB\TargetB']);
        $c4->register('App\ModuleB\TargetB', fn (): \stdClass => new \stdClass());
        $c4->validateAndFreeze();
        $this->ok($c4->get('App\ModuleA\One') instanceof \stdClass, 'scope: repeated same-target edge deduped');

        // module scope with budget 0 denies everything
        $c5 = new Container();
        $c5->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['App\ModuleB' => NamespaceRadixTree::SCOPE_MODULE],
        ));
        $c5->register('App\ModuleA\One', fn (): \stdClass => new \stdClass(), ['App\ModuleB\TargetB']);
        $c5->register('App\ModuleB\TargetB', fn (): \stdClass => new \stdClass());
        $this->throws(
            ModuleDependencyViolationException::class,
            fn () => $c5->validateAndFreeze(),
            'scope: module scope with zero budget denies'
        );

        // internal target reached through an ALIAS is still denied (canonical dep)
        $c6 = new Container();
        $c6->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['Zef\Framework' => NamespaceRadixTree::SCOPE_INTERNAL],
        ));
        $c6->register('Zef\Framework\Database', fn (): \stdClass => new \stdClass());
        $c6->alias('core.db', 'Zef\Framework\Database');
        $c6->register('App\A', fn (): \stdClass => new \stdClass(), ['core.db']);
        $this->throws(
            ModuleDependencyViolationException::class,
            fn () => $c6->validateAndFreeze(),
            'scope: alias-resolved canonical dep still checked'
        );

        // public default: unannotated namespaces unaffected
        $c7 = new Container();
        $c7->register('App\A', fn (): \stdClass => new \stdClass(), ['Other\B']);
        $c7->register('Other\B', fn (): \stdClass => new \stdClass());
        $c7->validateAndFreeze();
        $this->ok($c7->get('App\A') instanceof \stdClass, 'scope: unannotated namespaces remain public');
    }

    private function v211GetByPrefix(): void
    {
        $c = new Container();
        $seq = 'cba';
        for ($i = 0; $i < 3; ++$i) {
            $id = 'App\Middleware\\' . ucfirst($seq[$i]) . 'Middleware';
            $c->register($id, fn (): MiddlewareProbe => new MiddlewareProbe($id));
        }
        $c->register('App\NotMiddleware\X', fn (): MiddlewareProbe => new MiddlewareProbe('App\NotMiddleware\X'));
        $c->validateAndFreeze();

        $resolved = $c->getByPrefix('App\Middleware');
        $this->ok(array_keys($resolved) === [
            'App\Middleware\AMiddleware',
            'App\Middleware\BMiddleware',
            'App\Middleware\CMiddleware',
        ], 'getByPrefix: batch fetch is ID-sorted and complete');
        $this->ok(
            $resolved['App\Middleware\AMiddleware']->id === 'App\Middleware\AMiddleware',
            'getByPrefix: instances resolved through factories'
        );
        $this->ok(!isset($resolved['App\NotMiddleware\X']), 'getByPrefix: subtree boundary respected');
        $this->ok($c->getByPrefix('App\Nothing') === [], 'getByPrefix: empty subtree yields empty map');
        $this->ok($c->getByPrefix('App\Middlewar') === [], 'getByPrefix: partial segment yields empty map');
        $this->ok($c->getIdsByPrefix('App\Middleware') === array_keys($resolved), 'getIdsByPrefix: mirrors batch keys');

        $c->reset(true);
        $this->ok(
            $c->getByPrefix('App\Middleware')['App\Middleware\AMiddleware'] instanceof MiddlewareProbe,
            'getByPrefix: re-resolves after singleton reset'
        );

        // alias under prefix is included and resolves through the alias
        $c2 = new Container();
        $c2->register('App\Middleware\AuthMiddleware', fn (): MiddlewareProbe => new MiddlewareProbe('auth'));
        $c2->alias('App\Middleware\AuthAlias', 'App\Middleware\AuthMiddleware');
        $c2->validateAndFreeze();
        $batch = $c2->getByPrefix('App\Middleware');
        $this->ok(
            isset($batch['App\Middleware\AuthAlias']) && isset($batch['App\Middleware\AuthMiddleware']),
            'getByPrefix: aliases are indexed next to definitions'
        );
        $this->ok(
            $batch['App\Middleware\AuthAlias'] === $batch['App\Middleware\AuthMiddleware'],
            'getByPrefix: alias resolves to the same singleton instance'
        );

        // non-instantiating variant really does not instantiate (factory counter)
        MiddlewareProbe::$built = 0;
        $c3 = new Container();
        $c3->register('App\Middleware\Counter', fn (): MiddlewareProbe => new MiddlewareProbe('x'));
        $c3->validateAndFreeze();
        $c3->getIdsByPrefix('App\Middleware');
        $this->ok(MiddlewareProbe::$built === 0, 'getIdsByPrefix: zero instantiation');
        $c3->getByPrefix('App\Middleware');
        $this->ok(MiddlewareProbe::$built === 1, 'getByPrefix: exactly one instantiation');

        // pre-freeze rejection
        $c4 = new Container();
        $this->throws(\LogicException::class, fn (): array => $c4->getByPrefix('App'), 'getByPrefix: pre-freeze rejected');
        $this->throws(\LogicException::class, fn (): array => $c4->getIdsByPrefix('App'), 'getIdsByPrefix: pre-freeze rejected');
    }

    private function v211Fallback(): void
    {
        $c = new Container();
        $c->registerNamespaceFallback('App\Domain\Unmapped', fn (ContainerInterface $ctx, string $id): UnmappedService => new UnmappedService($id));
        $c->registerNamespaceFallback('App\Domain\Unmapped\Deep', fn (ContainerInterface $ctx, string $id): DeepUnmappedService => new DeepUnmappedService($id), ServiceLifetime::TRANSIENT);
        $c->registerNamespaceFallback('App\Domain\Unmapped\NullZone', fn (): null => null);
        $c->registerNamespaceFallback('App\Domain\Unmapped\Boom', fn () => throw new \RuntimeException('boom'));
        $c->register('App\Domain\Unmapped\Real', fn (): MiddlewareProbe => new MiddlewareProbe('real'));
        $c->validateAndFreeze();

        // longest prefix wins + factory receives the requested id (Deep = TRANSIENT)
        $deep = $c->get('App\Domain\Unmapped\Deep\Handler');
        $this->ok(
            $deep instanceof DeepUnmappedService && $deep->id === 'App\Domain\Unmapped\Deep\Handler',
            'fallback: longest registered prefix wins, id passed through'
        );

        // singleton caching per requested id (shorter prefix = SINGLETON)
        $alpha = $c->get('App\Domain\Unmapped\Alpha');
        $this->ok(
            $alpha instanceof UnmappedService && $c->get('App\Domain\Unmapped\Alpha') === $alpha,
            'fallback: singleton cached per requested id'
        );
        $beta = $c->get('App\Domain\Unmapped\Beta');
        $this->ok($beta instanceof UnmappedService && $beta !== $alpha, 'fallback: distinct ids get distinct instances');

        // transient fallback instantiates every call
        $this->ok(
            $c->get('App\Domain\Unmapped\Deep\T1') !== $c->get('App\Domain\Unmapped\Deep\T1'),
            'fallback: TRANSIENT instantiates per call'
        );

        // PSR-11 has() honors fallback coverage
        $this->ok($c->has('App\Domain\Unmapped\Whatever'), 'has(): covered unknown id is true');
        $this->ok(!$c->has('App\Elsewhere\Unknown'), 'has(): uncovered unknown id is false');

        // fallback never shadows registered services
        $real = $c->get('App\Domain\Unmapped\Real');
        $this->ok($real instanceof MiddlewareProbe && $real->id === 'real', 'fallback: registered service wins over fallback');

        // uncovered unknown still throws the canonical exception
        $this->throws(
            ServiceNotFoundException::class,
            fn (): mixed => $c->get('App\Elsewhere\Unknown'),
            'fallback: uncovered unknown id throws ServiceNotFoundException'
        );

        // null-returning factory is rejected
        $this->throws(
            ServiceResolutionException::class,
            fn (): mixed => $c->get('App\Domain\Unmapped\NullZone\X'),
            'fallback: null-returning factory rejected'
        );

        // failing factory is wrapped with context
        $this->throws(
            ServiceResolutionException::class,
            fn (): mixed => $c->get('App\Domain\Unmapped\Boom\X'),
            'fallback: failing factory wrapped as ServiceResolutionException'
        );

        // reset(true) drops fallback singletons too
        $r1 = $c->get('App\Domain\Unmapped\ResetProbe');
        $c->reset(true);
        $this->ok($c->get('App\Domain\Unmapped\ResetProbe') !== $r1, 'fallback: reset(true) clears singleton cache');

        // fallback works pre-freeze (composition time)
        $c2 = new Container();
        $c2->registerNamespaceFallback('Lazy\Namespace', fn (): MiddlewareProbe => new MiddlewareProbe('lazy'));
        $this->ok($c2->get('Lazy\Namespace\Service') instanceof MiddlewareProbe, 'fallback: usable before freeze');

        // REQUEST lifetime is not applicable to fallbacks
        $c3 = new Container();
        $this->throws(
            InvalidConfigurationException::class,
            fn () => $c3->registerNamespaceFallback('X', fn (): \stdClass => new \stdClass(), ServiceLifetime::REQUEST),
            'fallback: REQUEST lifetime rejected'
        );

        // registration after freeze is rejected
        $c3->validateAndFreeze();
        $this->throws(
            \LogicException::class,
            fn () => $c3->registerNamespaceFallback('Y', fn (): \stdClass => new \stdClass()),
            'fallback: post-freeze registration rejected'
        );

        // budget of 64
        $c4 = new Container();
        $this->throws(\OverflowException::class, function () use ($c4): void {
            for ($i = 0; $i < 65; ++$i) {
                $c4->registerNamespaceFallback('Budget\N' . $i, fn (): \stdClass => new \stdClass());
            }
        }, 'fallback: budget 64 enforced');

        // fallback NEVER rescues unknown graph deps — validation still guards them
        $c5 = new Container();
        $c5->registerNamespaceFallback('App\Domain\Unmapped', fn (): \stdClass => new \stdClass());
        $c5->register('App\Consumer', fn (): \stdClass => new \stdClass(), ['App\Domain\Unmapped\Ghost']);
        $this->throws(
            ServiceNotFoundException::class,
            fn () => $c5->validateAndFreeze(),
            'fallback: unknown graph dependency still rejected at freeze'
        );
    }

    private function v211Aot(): void
    {
        $tree = $this->sampleTree();
        $payload = $tree->exportArray();
        $encoded = 'return ' . var_export($payload, true) . ';';
        // False positive on argument shape, not provenance: `$encoded` is built three lines above as
        // `'return ' . var_export($payload, true) . ';'` where $payload comes from this suite's own
        // `$tree->exportArray()`; the test evaluates its own AOT fixture.
        // Registered in docs/security/php-sast.md §7.5.
        $restored = NamespaceRadixTree::fromArray(eval($encoded)); // nosemgrep: eval-use

        $this->ok($restored->containsExact('App\Domain\Users\CreateUser'), 'AOT: exact match preserved');
        $this->ok($restored->containsExact(Container::class), 'AOT: deep chain preserved');
        $this->ok($restored->idsUnderPrefix('App\Domain') === $tree->idsUnderPrefix('App\Domain'), 'AOT: prefix query preserved');
        $this->ok($restored->scopeOf('Zef\Framework\Cache') === $tree->scopeOf('Zef\Framework\Cache'), 'AOT: scope annotation preserved');
        $this->ok($restored->stats() === $tree->stats(), 'AOT: stats identical');
        $this->ok($restored->isSealed(), 'AOT: restored tree arrives sealed');
        $this->ok(
            !str_contains($encoded, 'Closure') && !str_contains($encoded, 'Reflection'),
            'AOT: export payload is closure-free and reflection-free'
        );
        $this->throws(
            \InvalidArgumentException::class,
            fn (): NamespaceRadixTree => NamespaceRadixTree::fromArray(['nope' => 1]),
            'AOT: malformed payload rejected'
        );
    }

    private function v211Lifecycle(): void
    {
        $c = new Container();
        $c->configureNamespacePolicy(new NamespaceScopePolicy(
            scopes: ['Zef\Framework' => NamespaceRadixTree::SCOPE_INTERNAL],
        ));
        $c->register('Zef\Framework\Db', fn (): DbProbe => new DbProbe());
        $c->register('Zef\Framework\Cache', fn (ContainerInterface $ctx, DbProbe $db): CacheProbe => new CacheProbe($db), ['Zef\Framework\Db']);
        $c->register('App\Handlers\Clock', fn (): ClockProbe => new ClockProbe());
        $c->register('App\Handlers\UserHandler', fn (ContainerInterface $ctx, ClockProbe $clock): UserHandlerProbe => new UserHandlerProbe($clock), ['App\Handlers\Clock']);
        $c->alias('db', 'Zef\Framework\Db');
        $c->validateAndFreeze();

        $tree = $c->namespaceTree();
        $this->ok($tree instanceof NamespaceRadixTree && $tree->isSealed(), 'lifecycle: sealed tree installed at freeze');
        $stats = $c->namespaceStats();
        $this->ok(is_array($stats) && $stats['serviceIds'] === 5, 'lifecycle: stats exposed via container (4 defs + 1 alias)');

        $cache = $c->get('Zef\Framework\Cache');
        $this->ok($cache instanceof CacheProbe && $cache->db instanceof DbProbe, 'lifecycle: internal subtree injection works');

        $handler = $c->get('App\Handlers\UserHandler');
        $this->ok(
            $handler instanceof UserHandlerProbe && $handler->clock instanceof ClockProbe,
            'lifecycle: app-side resolution unaffected by policy'
        );

        // root-level explicit get() through an alias is developer intent, not a graph edge
        $this->ok($c->get('db') instanceof DbProbe, 'lifecycle: explicit root lookup of internal service allowed');

        $batch = $c->getByPrefix('Zef\Framework');
        $this->ok(array_keys($batch) === ['Zef\Framework\Cache', 'Zef\Framework\Db'], 'lifecycle: getByPrefix over internal core');
        $this->ok($batch['Zef\Framework\Cache'] === $cache, 'lifecycle: batch reuses cached singletons');

        // immutability after freeze
        $this->throws(\LogicException::class, fn () => $tree->insert('App\Late'), 'lifecycle: post-freeze insert rejected');
        $this->throws(\LogicException::class, fn () => $tree->annotate('App', 'public'), 'lifecycle: post-freeze annotate rejected');
        $this->throws(
            \LogicException::class,
            fn () => $c->configureNamespacePolicy(new NamespaceScopePolicy()),
            'lifecycle: post-freeze policy install rejected'
        );

        // seal() is idempotent
        $tree->seal();
        $this->ok($tree->isSealed(), 'lifecycle: seal() idempotent');
    }

    private function v211EdgeSemantics(): void
    {
        $tree = new NamespaceRadixTree();
        $tree->insert('Same\Id');
        $tree->insert('Same\Id');
        $stats = $tree->stats();
        $this->ok($stats['serviceIds'] === 1, 'edge: duplicate insert is idempotent');

        $this->throws(\InvalidArgumentException::class, fn () => $tree->insert(''), 'edge: empty insert rejected');
        $this->throws(\InvalidArgumentException::class, fn () => $tree->annotate('', 'public'), 'edge: empty annotate rejected');
        $this->throws(\InvalidArgumentException::class, fn () => $tree->annotate('X', 'fortress'), 'edge: unknown scope kind rejected');
        $this->ok(!$tree->containsExact(''), 'edge: containsExact("") is false');

        $this->throws(\InvalidArgumentException::class, function (): void {
            new NamespaceScopePolicy(scopes: ['A' => 'fortress']);
        }, 'edge: policy rejects unknown scope kind');
        $this->throws(\InvalidArgumentException::class, function (): void {
            new NamespaceScopePolicy(maxCrossScopeRefs: -1);
        }, 'edge: policy rejects negative budget');
        $this->throws(\InvalidArgumentException::class, function (): void {
            new NamespaceScopePolicy(scopes: ['' => 'public']);
        }, 'edge: policy rejects empty prefix');

        $policy = new NamespaceScopePolicy(scopes: ['Zef\Framework' => 'internal']);
        $this->ok(array_key_exists('Zef\Framework\\', $policy->normalizedScopes()), 'edge: policy normalizes trailing separator');

        $this->ok(array_keys(NamespaceRadixTree::SCOPES) === [0, 1, 2], 'edge: scope kind registry intact');
    }
}

// ----------------------------------------------------------------------
// Fixtures
// ----------------------------------------------------------------------

final class MiddlewareProbe
{
    public static int $built = 0;

    public function __construct(public readonly string $id)
    {
        ++self::$built;
    }
}

final class UnmappedService
{
    public function __construct(public readonly string $id) {}
}

final class DeepUnmappedService
{
    public function __construct(public readonly string $id) {}
}

final class DbProbe {}

final class CacheProbe
{
    public function __construct(public readonly DbProbe $db) {}
}

final class ClockProbe {}

final class UserHandlerProbe
{
    public function __construct(public readonly ClockProbe $clock) {}
}
