<?php

declare(strict_types=1);

/*
 * ZEF Framework — CLI contract test.
 *
 * The native in-process suites (SelfTestSuitesTest) cover the framework
 * logic; this test additionally guards the public CLI entry point itself:
 * `bin/zef --self-test=<key>` must keep its subprocess contract (exit code,
 * human-readable output) for the CI workflow and for developers.
 */

namespace Zef\Test;

use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class ZefCliContractTest extends TestCase
{
    public function testCliSelfTestKeyExitsZeroAndPrintsSummary(): void
    {
        $php = \PHP_BINARY;
        $bin = __DIR__ . '/../bin/zef';

        self::assertFileExists($bin);

        $cmd = \escapeshellarg($php) . ' ' . \escapeshellarg($bin) . ' --self-test=container 2>&1';
        exec($cmd, $outputLines, $exitCode); // nosemgrep: exec-use
        $output = \implode("\n", $outputLines);

        self::assertSame(0, $exitCode, "CLI self-test must exit 0:\n{$output}");
        self::assertStringContainsString('[PASS]', $output, 'CLI must report suite success');
        self::assertMatchesRegularExpression('/PASSED:\s*\d+\s+FAILED:\s*0/', $output, 'CLI must print its summary line');
    }

    public function testCliReportsFailureExitCodeForUnknownFilter(): void
    {
        $php = \PHP_BINARY;
        $bin = __DIR__ . '/../bin/zef';

        $cmd = \escapeshellarg($php) . ' ' . \escapeshellarg($bin) . ' --self-test=no-such-suite-key 2>&1';
        exec($cmd, $outputLines, $exitCode); // nosemgrep: exec-use
        $output = \implode("\n", $outputLines);

        self::assertNotSame(0, $exitCode, 'unknown filter must not exit 0');
        self::assertStringContainsString('no suite matches', $output);
    }

    public function testCliPrintsVersionBanner(): void
    {
        $php = \PHP_BINARY;
        $bin = __DIR__ . '/../bin/zef';

        $cmd = \escapeshellarg($php) . ' ' . \escapeshellarg($bin) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode); // nosemgrep: exec-use

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('ZEF Framework', \implode("\n", $outputLines));
    }
}
