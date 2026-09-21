<?php

/**
 * ZEF Framework — PHPUnit bridge.
 *
 * The framework ships its own self-test runner (bin/zef --self-test).
 * This bridge exposes every suite to the standard PHPUnit workflow so
 * `composer test` (phpunit) verifies the exact same assertions the
 * zero-composer CLI runner executes — one process per suite, using the
 * real bin/zef entry point.
 */

declare(strict_types=1);

namespace Zef\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class SelfTestBridgeTest extends TestCase
{
    #[DataProvider('suiteKeyProvider')]
    public function testSelfTestSuite(string $key): void
    {
        [$exitCode, $output] = $this->runSelfTest('--self-test=' . $key);
        self::assertSame(0, $exitCode, "suite '{$key}' failed:\n" . $output);
        self::assertStringContainsString('FAILED: 0', $output, "suite '{$key}' reported failures:\n" . $output);
    }

    /** @return array<string, array{string}> */
    public static function suiteKeyProvider(): array
    {
        return [
            'psr' => ['psr'],
            'routes' => ['routes'],
            'container' => ['container'],
            'concurrency' => ['concurrency'],
            'security' => ['security'],
            'request' => ['request'],
            'router' => ['router'],
            'pipeline' => ['pipeline'],
            'psr7' => ['psr7'],
            'hardening' => ['hardening'],
            'json' => ['json'],
            'gate' => ['gate'],
            'beta3' => ['beta3'],
            'v260' => ['v260'],
            'v270' => ['v270'],
            'v280' => ['v280'],
            'v290' => ['v290'],
            'v210' => ['v210'],
            'v211' => ['v211'],
        ];
    }

    public function testFullSelfTestSuite(): void
    {
        [$exitCode, $output] = $this->runSelfTest('--self-test');
        self::assertSame(0, $exitCode, "full self-test failed:\n" . $output);
        self::assertStringContainsString('FAILED: 0', $output);
    }

    /** @return array{int, string} */
    private function runSelfTest(string $arg): array
    {
        $bin = __DIR__ . '/../bin/zef';
        $cmd = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg($arg) . ' 2>&1';
        exec($cmd, $outputLines, $exitCode);

        return [$exitCode, implode("\n", $outputLines)];
    }
}
