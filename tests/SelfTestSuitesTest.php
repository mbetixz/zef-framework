<?php

declare(strict_types=1);

/*
 * ZEF Framework — Native PHPUnit migration of every self-test suite.
 *
 * History: the 19 ZEF suites were executed through `bin/zef --self-test=<key>`
 * child processes, bridged into PHPUnit by SelfTestBridgeTest. Because the
 * framework code ran in a separate process, the coverage driver (pcov) could
 * never observe it, capping measured coverage at 0%.
 *
 * This test runs the very same suites IN-PROCESS through CliRunner::runOne(),
 * so every framework class, method and line they exercise is recorded by the
 * coverage driver while the assertions themselves stay identical (501 checks).
 * The subprocess behaviour is still guarded separately by ZefCliContractTest.
 */

namespace Zef\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SelfTestSuitesTest extends TestCase
{
    /**
     * Every suite runs natively in this process. A suite passes only when
     * its exit code is 0 AND it actually executed a positive number of
     * assertions — guarding against silently-matched empty runs.
     */
    #[DataProvider('suiteProvider')]
    public function testZefSelfTestSuiteRunsInProcess(string $key): void
    {
        $runner = new CliRunner();

        ob_start();

        try {
            $exitCode = $runner->runOne($key);
        } finally {
            $output = (string) ob_get_clean();
        }

        $summary = $GLOBALS['zef_test_summary'] ?? null;
        $passed = \is_array($summary) ? ($summary['passed'] ?? 0) : 0;

        self::assertSame(
            0,
            $exitCode,
            "Self-test suite '{$key}' reported failures:\n{$output}",
        );
        self::assertGreaterThan(
            0,
            $passed,
            "Self-test suite '{$key}' executed no assertions — broken wiring:\n{$output}",
        );
        self::assertStringContainsString('[PASS]', $output, "suite '{$key}' did not print its PASS marker");
    }

    /**
     * The canonical suite registry. Keep in sync with CliRunner::run().
     *
     * @return array<string, array{0: string}>
     */
    public static function suiteProvider(): array
    {
        return [
            'psr (PSR contracts)' => ['psr'],
            'routes (Application routes)' => ['routes'],
            'container (Container lifetime/cycles)' => ['container'],
            'concurrency (Concurrent request scope isolation)' => ['concurrency'],
            'security (Security/header/URI)' => ['security'],
            'request (Request/factory boundaries)' => ['request'],
            'router (Router ambiguity)' => ['router'],
            'pipeline (Pipeline/error boundary)' => ['pipeline'],
            'psr7 (PSR-7 edge cases & resource safety)' => ['psr7'],
            'hardening (Final production hardening)' => ['hardening'],
            'json (JSON scalar boundary)' => ['json'],
            'gate (Zero critical bugs gate)' => ['gate'],
            'beta3 (beta3 hardening)' => ['beta3'],
            'v260 (v2.6.0 regression)' => ['v260'],
            'v270 (v2.7.0 edge-case regression)' => ['v270'],
            'v280 (v2.8.0 feature suite)' => ['v280'],
            'v290 (v2.9.0 autowiring suite)' => ['v290'],
            'v210 (v2.10.0 enterprise suite)' => ['v210'],
            'v211 (v2.11.0 radix-tree suite)' => ['v211'],
        ];
    }

    /**
     * The full self-test (all 19 suites sequentially, exactly like
     * `bin/zef --self-test`) must also stay green inside one process.
     */
    public function testFullSelfTestRunsAllSuitesInOneProcess(): void
    {
        $runner = new CliRunner();

        ob_start();

        try {
            $exitCode = $runner->run(false);
        } finally {
            ob_end_clean();
        }

        $summary = $GLOBALS['zef_test_summary'] ?? null;
        $passed = \is_array($summary) ? ($summary['passed'] ?? 0) : 0;
        $failed = \is_array($summary) ? ($summary['failed'] ?? -1) : -1;

        self::assertSame(0, $exitCode, 'full self-test must exit 0');
        self::assertSame(0, $failed, 'full self-test must record zero failed checks');
        self::assertGreaterThanOrEqual(501, $passed, 'full self-test must keep its 501-check baseline');
    }
}
