<?php

declare(strict_types=1);

/*
 * Issue #94 — hermetic ConsoleIO factory for CLI command tests.
 *
 * ConsoleIO defaults its streams to the real STDOUT/STDERR. A CLI command
 * test that instantiates it bare therefore writes its error diagnostics to
 * the phpunit process's own stderr. PHPUnit tolerates that, but Infection's
 * InitialTestsRunner stops the initial suite on the FIRST stderr byte
 * (Symfony Process::stop() -> SIGTERM -> exit code 143), which made the
 * aggregate mutation release gate fail every run since it was created.
 *
 * Every assertion in those tests goes through ConsoleIO::outLog()/errLog()
 * — the in-memory records, kept regardless of the streams — so swapping the
 * streams for php://memory changes no observable behaviour. It only makes
 * the tests hermetic: zero bytes to the real process streams.
 *
 * See docs/mutation/README.md ("The initial suite must write zero bytes to
 * STDERR") for the invariant this factory exists to uphold.
 */

namespace Zef\Test\Unit;

use Zef\Framework\Console\ConsoleIO;

/**
 * @internal
 */
final class HermeticConsoleIo
{
    /**
     * A ConsoleIO whose out/err streams go to memory, not the process streams.
     */
    public static function create(): ConsoleIO
    {
        $out = fopen('php://memory', 'wb');
        $err = fopen('php://memory', 'wb');
        if ($out === false || $err === false) {
            throw new \RuntimeException('Unable to create memory streams for a hermetic ConsoleIO.');
        }

        return new ConsoleIO($out, $err);
    }
}
