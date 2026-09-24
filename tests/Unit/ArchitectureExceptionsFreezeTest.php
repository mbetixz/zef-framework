<?php

declare(strict_types=1);

/*
 * Guild Action Item 1 — audit deptrac exceptions (follow-up guard).
 *
 * deptrac.yaml documents deliberate exception layers. After the issue
 * #36 exit ramp landed in full, Compat (pure PSR contracts, by design)
 * is the only exception layer left.
 * The risk called out by the audit is *silent* growth: every new
 * exception widens the coupling the hexagonal rules are supposed to
 * prevent, and without a tripwire nothing fails until the architecture
 * has already drifted.
 *
 * This test freezes the entire exception graph — the layer set, every
 * collector path, and the full ruleset edge map. ANY change to the
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
 *      OriginPolicySpec (OriginPolicy relocated to Domain/Security),
 *      TrustedProxy (TrustedProxyMatcher relocated to Domain/Http),
 *      ContainerImpl (ServiceRegistrarInterface composition port; the
 *      provider contracts now type the narrow registrar surface instead
 *      of the concrete container), and OtlpExporter (the Telemetry facade
 *      now composes its default exporter through the
 *      OtlpExporterFactoryInterface port in Domain/Observability; the
 *      kernel composition root wires the default via container config)
 *      were retired per issue #36. The only remaining documented
 *      long-term plan is an optional instance-based EnvInterface for
 *      composition-root injection.
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
    ];

    /**
     * Kolektor per layer (regex direktori persis seperti di deptrac.yaml).
     * Negative-lookahead di layer dasar ADALAH mekanisme carve-out —
     * membekukannya berarti exception baru tidak bisa masuk diam-diam.
     */
    private const array COLLECTORS = [
        'Domain' => ['src/Domain/.*'],
        'Application' => ['src/Application/.*'],
        'Infrastructure' => ['src/Infrastructure/.*'],
        'Adapters' => ['src/Adapters/.*'],
        'Compat' => ['src/Compat/.*'],
        'App' => ['src/Bootstrap\.php', 'src/Middleware/.*'],
        'Module' => ['modules/.*'],
        'Plugin' => ['plugins/.*'],
    ];

    /**
     * Peta edge ruleset lengkap — layer => daftar layer yang boleh dipakai.
     */
    private const array RULESET = [
        'Domain' => ['Compat'],
        'Compat' => [],
        'Application' => ['Domain', 'Compat'],
        'Infrastructure' => ['Domain', 'Application', 'Compat'],
        'Adapters' => ['Domain', 'Application', 'Infrastructure', 'Compat'],
        'App' => ['Domain', 'Application', 'Infrastructure', 'Adapters', 'Compat', 'Module', 'Plugin'],
        'Module' => ['Domain', 'Application', 'Infrastructure', 'Adapters', 'Compat'],
        'Plugin' => ['Domain', 'Application', 'Infrastructure', 'Adapters', 'Compat'],
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
     * End state issue #36: seluruh carve-out sudah pensiun — Compat
     * (kontrak PSR murni, keep-by-design) adalah SATU-SATUNYA layer
     * exception yang tersisa, dan mekanisme carve-out (layer kelas-tunggal
     * atau negative-lookahead di layer dasar) tidak boleh kembali
     * diam-diam; snapshot beku di atas sudah memaksa perubahan sadar.
     */
    public function testCompatIsTheOnlyExceptionLayerLeft(): void
    {
        $base = ['Domain', 'Application', 'Infrastructure', 'Adapters', 'App', 'Module', 'Plugin'];

        self::assertSame(
            ['Compat'],
            array_values(array_diff(self::LAYERS, $base)),
            'issue #36 end state: layer exception hanya boleh Compat — carve-out baru = keputusan sadar via snapshot',
        );

        foreach ($this->layerCollectors() as $layer => $patterns) {
            foreach ($patterns as $pattern) {
                self::assertStringNotContainsString('(?!', $pattern, "layer {$layer} menghidupkan ulang mekanisme carve-out lookahead");
            }
        }
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
