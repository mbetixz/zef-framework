<?php

declare(strict_types=1);

/*
 * Guild Action Item 1 — audit deptrac exceptions (follow-up guard).
 *
 * deptrac.yaml documents deliberate exception layers (Compat + the
 * single-class carve-outs: OtlpExporter, ContainerImpl).
 * The risk called out by the audit is *silent* growth: every new
 * exception widens the coupling the hexagonal rules are supposed to
 * prevent, and without a tripwire nothing fails until the architecture
 * has already drifted.
 *
 * This test freezes the entire exception graph — the layer set, every
 * collector path, the negative-lookahead carve-outs inside the base
 * layer collectors, and the full ruleset edge map. ANY change to the
 * dependency graph now requires a conscious diff against this snapshot.
 *
 * When this test fails on purpose:
 *   1. If the new exception is justified, update the snapshot below AND
 *      extend the header documentation in deptrac.yaml.
 *   2. Prefer the exit ramp instead: replace the carve-out with a pure
 *      port/interface inside src/Domain, then DELETE the exception here —
 *      the way EnvConfig (Env relocated to Domain/Foundation),
 *      KernelSleeper (SleeperInterface port + SystemSleeper default),
 *      RouteDefSpec (RouteDefinition relocated to Domain/Router),
 *      OriginPolicySpec (OriginPolicy relocated to Domain/Security), and
 *      TrustedProxy (TrustedProxyMatcher relocated to Domain/Http)
 *      were retired per issue #36. The remaining documented long-term
 *      plan is the ContainerImpl provider contracts (plus an optional
 *      future instance-based EnvInterface for composition-root
 *      injection).
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @internal
 */
final class ArchitectureExceptionsFreezeTest extends TestCase
{
    /**
     * Semua layer yang dianalisis, urut file — freeze penuh.
     */
    private const array LAYERS = [
        'Domain',
        'Application',
        'Infrastructure',
        'Adapters',
        'Compat',
        'App',
        'Module',
        'Plugin',
        'OtlpExporter',
        'ContainerImpl',
    ];

    /**
     * Kolektor per layer (regex direktori persis seperti di deptrac.yaml).
     * Negative-lookahead di layer dasar ADALAH mekanisme carve-out —
     * membekukannya berarti exception baru tidak bisa masuk diam-diam.
     */
    private const array COLLECTORS = [
        'Domain' => ['src/Domain/.*'],
        'Application' => ['src/Application/(?!Container/Container\.php).*'],
        'Infrastructure' => ['src/Infrastructure/(?!Observability/OtlpHttpJsonExporter\.php).*'],
        'Adapters' => ['src/Adapters/.*'],
        'Compat' => ['src/Compat/.*'],
        'App' => ['src/Bootstrap\.php', 'src/Middleware/.*'],
        'Module' => ['modules/.*'],
        'Plugin' => ['plugins/.*'],
        'OtlpExporter' => ['src/Infrastructure/Observability/OtlpHttpJsonExporter\.php'],
        'ContainerImpl' => ['src/Application/Container/Container\.php'],
    ];

    /**
     * Peta edge ruleset lengkap — layer => daftar layer yang boleh dipakai.
     */
    private const array RULESET = [
        'Domain' => ['Compat', 'ContainerImpl'],
        'Compat' => [],
        'OtlpExporter' => ['Domain', 'Application', 'Infrastructure', 'Compat'],
        'ContainerImpl' => ['Domain', 'Application', 'Compat'],
        'Application' => ['Domain', 'Compat', 'OtlpExporter', 'ContainerImpl'],
        'Infrastructure' => ['Domain', 'Application', 'Compat'],
        'Adapters' => ['Domain', 'Application', 'Infrastructure', 'Compat', 'ContainerImpl'],
        'App' => ['Domain', 'Application', 'Infrastructure', 'Adapters', 'Compat', 'ContainerImpl', 'Module', 'Plugin'],
        'Module' => ['Domain', 'Application', 'Infrastructure', 'Adapters', 'Compat', 'ContainerImpl'],
        'Plugin' => ['Domain', 'Application', 'Infrastructure', 'Adapters', 'Compat', 'ContainerImpl'],
    ];

    /** Jalur analisis wajib tetap seluruh pohon first-party. */
    public function testAnalyzedPathsAreFrozen(): void
    {
        $paths = $this->configValue('paths');
        self::assertIsList($paths, 'deptrac.paths harus list');
        self::assertSame(['./src', './modules', './plugins'], $paths, 'jalur analisis deptrac tidak boleh berubah diam-diam');
    }

    /** Set layer + nama wajib identik dengan snapshot — layer baru = keputusan sadar. */
    public function testLayerSetIsFrozen(): void
    {
        self::assertSame(self::LAYERS, array_keys($this->layerCollectors()), 'set/urutan layer deptrac berubah — lihat panduan di header test ini');
    }

    /** Setiap kolektor layer (termasuk carve-out negative-lookahead) dibekukan eksak. */
    public function testLayerCollectorsAreFrozen(): void
    {
        self::assertSame(self::COLLECTORS, $this->layerCollectors(), 'kolektor layer deptrac berubah — carve-out baru tidak boleh masuk diam-diam');
    }

    /** Peta edge ruleset lengkap dibekukan — edge baru = perluasan coupling sadar. */
    public function testRulesetEdgesAreFrozen(): void
    {
        $raw = $this->configValue('ruleset');
        self::assertIsArray($raw, 'deptrac.ruleset harus mapping');

        $parsed = [];
        foreach ($raw as $layer => $allowed) {
            self::assertIsString($layer, 'kunci ruleset harus string');
            self::assertIsList($allowed, "ruleset[{$layer}] harus list");
            $parsed[$layer] = $allowed;
        }

        self::assertSame(self::RULESET, $parsed, 'ruleset deptrac berubah — edge exception baru tidak boleh masuk diam-diam');
    }

    /**
     * ContainerImpl — carve-out tersisa yang masih disorot audit Guild —
     * tetap berupa kelas konkret tunggal; kontrak migrasi ke port Domain
     * tercatat di header deptrac.yaml. EnvConfig, KernelSleeper,
     * RouteDefSpec, OriginPolicySpec, dan TrustedProxy sudah pensiun
     * (relokasi FQN-preserving ke src/Domain). Test ini menjaga
     * ContainerImpl tidak meluas jadi multi-kelas.
     */
    public function testHighlightedCarveOutsRemainSingleClass(): void
    {
        $collectors = $this->layerCollectors();

        self::assertSame(['src/Application/Container/Container\.php'], $collectors['ContainerImpl'], 'ContainerImpl wajib tetap satu kelas Container (jalur keluar: kontrak provider murni)');
    }

    // ------------------------------------------------------------- Helpers

    private function configValue(string $key): mixed
    {
        $raw = Yaml::parseFile(dirname(__DIR__, 2) . '/deptrac.yaml');
        self::assertIsArray($raw, 'deptrac.yaml harus parse menjadi mapping');

        $config = $raw['deptrac'] ?? null;
        self::assertIsArray($config, 'deptrac.yaml wajib punya root mapping "deptrac"');

        return $config[$key] ?? null;
    }

    /** @return array<string, list<string>> */
    private function layerCollectors(): array
    {
        $layers = $this->configValue('layers');
        self::assertIsArray($layers, 'deptrac.layers harus list');

        $out = [];
        foreach ($layers as $layer) {
            self::assertIsArray($layer, 'setiap layer harus mapping');
            $name = $layer['name'] ?? null;
            $collectors = $layer['collectors'] ?? null;
            self::assertIsString($name, 'layer.name harus string');
            self::assertIsArray($collectors, "collectors layer {$name} harus list");

            $values = [];
            foreach ($collectors as $collector) {
                self::assertIsArray($collector, "collector layer {$name} harus mapping");
                $type = $collector['type'] ?? null;
                $value = $collector['value'] ?? null;
                self::assertIsString($type, "collector.type layer {$name} harus string");
                self::assertIsString($value, "collector.value layer {$name} harus string");
                self::assertSame('directory', $type, "collector layer {$name} wajib bertipe directory (tipe baru = desain sadar)");
                $values[] = $value;
            }

            $out[$name] = $values;
        }

        return $out;
    }
}
