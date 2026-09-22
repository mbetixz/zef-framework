<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix — Fase 8 (mutation round 3): Infrastructure\Config zone
 * + Foundation\Env. Target mutan escape baseline f8-infra-a/f8-infra-b:
 * ModuleRegistry 15, ConfigAggregator 9, Env 7. Pola kunci: urutan resolusi
 * dependency (diamond), status graf 1/2, reverse shutdown, dan pesan persis.
 */

namespace Zef\Framework\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Config\ModuleContext;
use Zef\Framework\Config\ModuleDefinition;
use Zef\Framework\Config\ModuleInterface;
use Zef\Framework\Config\ModuleRegistrar;
use Zef\Framework\Config\ModuleRegistry;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Foundation\Env;

/**
 * @internal
 */
final class EdgeMatrixF8ConfigTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['ZEF_F8_INT', 'ZEF_F8_BOOL', 'ZEF_F8_CSV', 'ZEF_F8_STR'] as $n) {
            \putenv($n);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Env (Foundation)
    // ------------------------------------------------------------------

    /** Membunuh UnwrapTrim:31 — " 5 " harus di-trim sebelum validasi int. */
    public function testIntTrimsSurroundingWhitespace(): void
    {
        \putenv('ZEF_F8_INT= 5 ');
        self::assertSame(5, Env::int('ZEF_F8_INT', 0, 1, 10));
    }

    /** Membunuh Concat×4 pada pesan strict (line 36/43). */
    public function testStrictIntMessagesAreExact(): void
    {
        \putenv('ZEF_F8_INT=abc');

        try {
            Env::int('ZEF_F8_INT', 0, 1, 10, true);
            self::fail('non-integer must throw in strict mode');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('ZEF_F8_INT must be an integer.', $e->getMessage());
        }

        \putenv('ZEF_F8_INT=99');

        try {
            Env::int('ZEF_F8_INT', 0, 1, 10, true);
            self::fail('out-of-range must throw in strict mode');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('ZEF_F8_INT is outside its allowed range.', $e->getMessage());
        }
    }

    /** Membunuh UnwrapTrim:52 — string kosong/spasi kembali ke default bool. */
    public function testBoolWhitespaceFallsBackToDefault(): void
    {
        \putenv('ZEF_F8_BOOL=   ');
        self::assertTrue(Env::bool('ZEF_F8_BOOL', true));
        \putenv('ZEF_F8_BOOL=1');
        self::assertTrue(Env::bool('ZEF_F8_BOOL', false));
    }

    /** Guard csv: nilai kosong antar koma dibuang, hasil list murni. */
    public function testCsvDropsEmptyFragments(): void
    {
        \putenv('ZEF_F8_CSV= a , ,b ,,');
        self::assertSame(['a', 'b'], Env::csv('ZEF_F8_CSV'));
    }

    /** Membunuh UnwrapTrim:31 via jalur strict — env berisi spasi-saja: default, bukan throw. */
    public function testStrictIntWithWhitespaceOnlyEnvFallsBackToDefault(): void
    {
        \putenv('ZEF_F8_INT=   ');
        self::assertSame(0, Env::int('ZEF_F8_INT', 0, 1, 10, true));
    }

    /** Non-strict int di-clamp ke [min,max] dua arah. */
    public function testIntClampsBothSides(): void
    {
        \putenv('ZEF_F8_INT=99');
        self::assertSame(10, Env::int('ZEF_F8_INT', 0, 1, 10));
        \putenv('ZEF_F8_INT=-5');
        self::assertSame(1, Env::int('ZEF_F8_INT', 0, 1, 10));
    }

    // ------------------------------------------------------------------
    // ModuleRegistry
    // ------------------------------------------------------------------

    /** Membunuh PregMatchRemoveCaret:37 — nama berawalan invalid ditolak. */
    public function testModuleNameGrammarRejectsBadPrefix(): void
    {
        $registry = new ModuleRegistry();
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Invalid module name '!bad'.");
        $registry->add($this->module('!bad'));
    }

    /** Membunuh UnwrapStrToLower:40 — duplikasi beda huruf besar/kecil terdeteksi. */
    public function testDuplicateModuleNamesAreCaseInsensitive(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('Alpha'));
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Duplicate module 'alpha'.");
        $registry->add($this->module('alpha'));
    }

    /** Membunuh UnwrapStrToLower:44 — definition name beda case masih dianggap cocok. */
    public function testDefinitionNameCaseDifferenceIsAccepted(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('Alpha', defName: 'ALPHA'));
        self::assertCount(1, $registry->modules());
    }

    /** Membunuh ArrayOneItem:73 — providers() memetakan semua modul. */
    public function testProvidersCoversEveryModule(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('Alpha'));
        $registry->addProvider($this->provider('Beta', ['k' => 1]));
        $providers = $registry->providers();
        self::assertCount(2, $providers);
        self::assertInstanceOf(ConfigProviderInterface::class, $providers[0]);
        self::assertInstanceOf(ConfigProviderInterface::class, $providers[1]);
    }

    /** Membunuh status===2 (Increment/Decrement:86) dan state=2 (Dec/Inc:95) via diamond. */
    public function testDiamondDependencyResolvesOnceInOrder(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('d'));
        $registry->add($this->module('b', ['d']));
        $registry->add($this->module('c', ['d']));
        $registry->add($this->module('a', ['b', 'c']));

        $order = $registry->resolveOrder();
        self::assertSame(['d', 'b', 'c', 'a'], array_map(
            static fn (ModuleInterface $m): string => $m->getName(),
            $order,
        ));
    }

    /** Membunuh UnwrapStrToLower:93 — dependency 'BETA' menemukan modul 'Beta'. */
    public function testDependencyLookupIsCaseInsensitive(): void
    {
        $registry = new ModuleRegistry();
        $registry->add($this->module('Beta'));
        $registry->add($this->module('Alpha', ['BETA']));
        $order = $registry->resolveOrder();
        self::assertSame(['Beta', 'Alpha'], array_map(
            static fn (ModuleInterface $m): string => $m->getName(),
            $order,
        ));
    }

    /** Guard siklus + dependency hilang (pesanan persis). */
    public function testCycleAndMissingDependencyAreRejected(): void
    {
        $cyc = new ModuleRegistry();
        $cyc->add($this->module('a', ['a']));

        try {
            $cyc->resolveOrder();
            self::fail('self-cycle must throw');
        } catch (InvalidConfigurationException $e) {
            self::assertSame("Circular module dependency detected at 'a'.", $e->getMessage());
        }

        $miss = new ModuleRegistry();
        $miss->add($this->module('a', ['ghost']));
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Missing module dependency 'ghost'.");
        $miss->resolveOrder();
    }

    /** Membunuh TrueValue:144 — startAll idempoten (start hanya sekali). */
    public function testStartAllRunsEachStartOnce(): void
    {
        [$registry, $spies] = $this->bootedRegistry(['a', 'b']);
        $registry->bootAll($this->container()); // boot kedua harus no-op (Membunuh ReturnRemoval:125)
        $registry->startAll($this->container());
        $registry->startAll($this->container());
        self::assertTrue($registry->isStarted());
        self::assertSame(['register:a', 'register:b', 'boot:a', 'boot:b', 'start:a', 'start:b'], $spies->events, 'start() tidak boleh terpanggil dua kali');
    }

    /** Membunuh ReturnRemoval:125 — registerAll idempoten. */
    public function testRegisterAllIsIdempotent(): void
    {
        $spies = new F8ModuleSpy();
        $registry = new ModuleRegistry();
        $registry->add(new F8SpyModule('a', $spies));
        $registry->registerAll(new F8NoopRegistrar(), $this->container());
        $registry->registerAll(new F8NoopRegistrar(), $this->container());
        self::assertSame(['register:a'], $spies->events, 'register() hanya sekali');
    }

    /** Membunuh UnwrapArrayReverse:152 + FalseValue:158/159 — shutdown terbalik & reset flag. */
    public function testShutdownRunsInReverseAndResetsLifecycle(): void
    {
        [$registry, $spies] = $this->bootedRegistry(['a', 'b', 'c']);
        $registry->startAll($this->container());
        $registry->shutdownAll($this->container());
        self::assertSame(
            ['register:a', 'register:b', 'register:c', 'boot:a', 'boot:b', 'boot:c', 'start:a', 'start:b', 'start:c', 'shutdown:c', 'shutdown:b', 'shutdown:a'],
            $spies->events,
        );
        self::assertFalse($registry->isStarted());
        self::assertFalse($registry->isBooted());
    }

    /** Guard: modul yang melempar saat shutdown tidak menghentikan modul lain. */
    public function testShutdownSwallowsModuleFailures(): void
    {
        [$registry, $spies] = $this->bootedRegistry(['a', 'b']);
        $registry->bootAll($this->container());
        $spies->throwOnShutdown = 'b';
        $registry->shutdownAll($this->container());
        self::assertSame(['shutdown:b', 'shutdown:a'], array_slice($spies->events, -2));
        self::assertSame('register:a', $spies->events[0]);
        self::assertFalse($registry->isStarted());
    }

    /** Guard add-after-register (freeze). */
    public function testAddAfterRegistrationIsFrozen(): void
    {
        [$registry] = $this->bootedRegistry(['a']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot add modules after registration has started.');
        $registry->add($this->module('late'));
    }

    // ------------------------------------------------------------------
    // ConfigAggregator
    // ------------------------------------------------------------------

    /** Membunuh LogicalOr:35 — nama kosong ditolak (mutan && tidak melempar). */
    public function testEmptyProviderNameIsRejected(): void
    {
        $agg = new ConfigAggregator();
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Invalid module name ''.");
        $agg->addProvider($this->provider('', ['k' => 1]));
    }

    /** Membunuh PregMatchRemoveCaret/Dollar:35. */
    public function testProviderNameGrammarIsAnchored(): void
    {
        $agg = new ConfigAggregator();
        foreach (['!bad', 'bad!'] as $name) {
            try {
                $agg->addProvider($this->provider($name, []));
                self::fail("provider name {$name} must be rejected");
            } catch (InvalidConfigurationException $e) {
                self::assertSame("Invalid module name '{$name}'.", $e->getMessage());
            }
        }
        self::assertCount(0, $agg->providers());
    }

    /** Membunuh UnwrapStrToLower:40 — duplikasi case-insensitive. */
    public function testProviderDuplicateIsCaseInsensitive(): void
    {
        $agg = new ConfigAggregator();
        $agg->addProvider($this->provider('Alpha', ['a' => 1]));
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage("Duplicate module/provider 'alpha' (case-insensitive collision).");
        $agg->addProvider($this->provider('alpha', ['b' => 2]));
    }

    /** Membunuh ReturnRemoval:51 — merge() kedua tidak boleh re-merge (hitung getConfig). */
    public function testMergeIsIdempotent(): void
    {
        $provider = $this->provider('Alpha', ['x' => ['y' => 7]]);
        $agg = new ConfigAggregator();
        $agg->addProvider($provider);
        $first = $agg->merge();
        $second = $agg->merge();
        self::assertSame($first, $second);
        self::assertSame(1, $provider->calls, 'getConfig() tidak boleh dipanggil ulang oleh merge() kedua');
    }

    /** Membunuh UnwrapStrToLower:68 — kunci hasil merge lowercase. */
    public function testMergedKeysAreLowercased(): void
    {
        $agg = new ConfigAggregator();
        $agg->addProvider($this->provider('Alpha', ['x' => 1]));
        self::assertSame(['alpha'], array_keys($agg->all()));
        self::assertSame(1, $agg->get('alpha.x'));
    }

    /** Membunuh UnwrapFinally:61 — merging direset walau provider melempar (TypeError di dalam try). */
    public function testFailedMergeThenRecoverySucceeds(): void
    {
        $provider = $this->provider('P', []);
        $provider->config = 'not-an-array';
        $agg = new ConfigAggregator();
        $agg->addProvider($provider);

        try {
            $agg->merge();
            self::fail('broken provider must blow up');
        } catch (\TypeError) {
            // getConfig(): array — tipe melanggar di dalam try; finally tetap wajib reset.
        }

        $provider->config = ['ok' => true];
        self::assertSame(['p' => ['ok' => true]], $agg->merge(), 'merging harus ter-reset oleh finally');
    }

    /** Membunuh TrueValue:73 — addProvider setelah merge dibekukan. */
    public function testAddProviderAfterMergeIsRejected(): void
    {
        $agg = new ConfigAggregator();
        $agg->addProvider($this->provider('A', ['k' => 1]));
        $agg->merge();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Cannot add provider after configuration has been merged.');
        $agg->addProvider($this->provider('B', []));
    }

    /** Guard re-entrancy (pesan persis) + navigasi dot. */
    public function testReentrantMergeAndDotNavigation(): void
    {
        $agg = new ConfigAggregator();
        $lazy = new readonly class($agg) implements ConfigProviderInterface {
            public function __construct(private ConfigAggregator $agg) {}

            #[\Override]
            public function getModuleName(): string
            {
                return 'Lazy';
            }

            #[\Override] // @phpstan-ignore-line
            public function getConfig(): array
            {
                $this->agg->get('other.key');

                return [];
            }
        };
        $agg->addProvider($this->provider('Other', ['key' => 1]));
        $agg->addProvider($lazy);

        try {
            $agg->merge();
            self::fail('reentrant merge must throw');
        } catch (\LogicException $e) {
            self::assertSame(
                'Reentrant configuration read: a provider called ConfigAggregator::get()/all()/merge() while configuration is being merged.',
                $e->getMessage(),
            );
        }

        $plain = new ConfigAggregator();
        $plain->addProvider($this->provider('Db', ['primary' => ['host' => 'h1', 'port' => 5432]]));
        self::assertSame('h1', $plain->get('db.primary.host'));
        self::assertSame(5432, $plain->get('db.primary.port'));
        self::assertNull($plain->get('db.slave.host'));
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function module(string $name, array $deps = [], ?string $defName = null): ModuleInterface // @phpstan-ignore-line
    {
        return new readonly class($name, $deps, $defName ?? $name) implements ModuleInterface {
            public function __construct(// @phpstan-ignore-line
                private string $name,
                private array $deps,
                private string $defName,
            ) {}

            #[\Override]
            public function getName(): string
            {
                return $this->name;
            }

            #[\Override]
            public function getDefinition(): ModuleDefinition
            {
                return new ModuleDefinition($this->defName, dependencies: $this->deps); // @phpstan-ignore-line
            }

            #[\Override]
            public function register(ModuleContext $context): void {}

            #[\Override]
            public function boot(ModuleContext $context): void {}

            #[\Override]
            public function start(ModuleContext $context): void {}

            #[\Override]
            public function shutdown(ModuleContext $context): void {}
        };
    }

    private function provider(string $name, array $config): SwitchableF8Provider // @phpstan-ignore-line
    {
        return new SwitchableF8Provider($name, $config);
    }

    /** @return array{0: ModuleRegistry, 1: F8ModuleSpy} */
    private function bootedRegistry(array $names): array // @phpstan-ignore-line
    {
        $spies = new F8ModuleSpy();
        $registry = new ModuleRegistry();
        foreach ($names as $n) {
            $registry->add(new F8SpyModule($n, $spies)); // @phpstan-ignore-line
        }
        $registry->registerAll(new F8NoopRegistrar(), $this->container());
        $registry->bootAll($this->container());

        return [$registry, $spies];
    }

    private function container(): ContainerInterface
    {
        return new F8EmptyContainer();
    }
}

/**
 * @internal
 */
final class SwitchableF8Provider implements ConfigProviderInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly string $name,
        public mixed $config,
    ) {}

    #[\Override]
    public function getModuleName(): string
    {
        return $this->name;
    }

    #[\Override] // @phpstan-ignore-line
    public function getConfig(): array
    {
        ++$this->calls;

        return $this->config; // @phpstan-ignore-line
    }
}

/**
 * @internal — modul yang merekam lifecycle ke spy bersama
 */
final class F8SpyModule implements ModuleInterface
{
    public function __construct(
        private readonly string $name,
        public readonly F8ModuleSpy $spy,
    ) {}

    #[\Override]
    public function getName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function getDefinition(): ModuleDefinition
    {
        return new ModuleDefinition($this->name);
    }

    #[\Override]
    public function register(ModuleContext $context): void
    {
        $this->spy->events[] = 'register:' . $this->name;
    }

    #[\Override]
    public function boot(ModuleContext $context): void
    {
        $this->spy->events[] = 'boot:' . $this->name;
    }

    #[\Override]
    public function start(ModuleContext $context): void
    {
        $this->spy->events[] = 'start:' . $this->name;
    }

    #[\Override]
    public function shutdown(ModuleContext $context): void
    {
        $this->spy->events[] = 'shutdown:' . $this->name;
        if ($this->spy->throwOnShutdown === $this->name) {
            throw new \RuntimeException('shutdown failure of ' . $this->name);
        }
    }
}

/**
 * @internal
 */
final class F8ModuleSpy
{
    /** @var list<string> */
    public array $events = [];
    public ?string $throwOnShutdown = null;
}

/**
 * @internal
 */
final class F8NoopRegistrar implements ModuleRegistrar
{
    #[\Override]
    public function registerModule(string $module, array|ModuleDefinition $config): void {}
}

/**
 * @internal
 */
final class F8EmptyContainer implements ContainerInterface
{
    #[\Override]
    public function get(string $id): mixed
    {
        throw new \RuntimeException("not found: {$id}");
    }

    #[\Override]
    public function has(string $id): bool
    {
        return false;
    }
}
