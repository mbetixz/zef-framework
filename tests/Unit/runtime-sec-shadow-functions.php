<?php

declare(strict_types=1);

/*
 * ZEF Framework — issue #90 round 2 (zone `ad-runtime-sec`) test doubles.
 *
 * Namespace-shadowed global functions: PHP resolves an UNQUALIFIED call
 * inside a namespace to a namespaced function when one exists, falling back
 * to the global otherwise. Declaring Zef\Framework\Runtime\memory_get_usage
 * and Zef\Framework\Runtime\usleep lets the mutation-debt tests pin the
 * EXACT values RoadRunnerRuntime and BlockingSleeper derive from them
 * (memory ratio arithmetic, microsecond conversion) deterministically —
 * the real clock/allocator answers are neither stable nor observable.
 *
 * The shadows are loaded by the suite bootstrap (tests/bootstrap.php) so
 * they exist BEFORE any Zef\Framework\Runtime call executes: PHP binds an
 * unqualified call at its first execution, so a late-loaded shadow would
 * never intercept. They are inert unless a test arms them via the globals
 * below, so every other test keeps observing real behaviour. usleep()
 * always delegates to the real sleep (timing-sensitive tests depend on the
 * actual delay) and only RECORDS its argument; memory_get_usage() delegates
 * whenever no fake is armed. Both fall back to the global implementation
 * with a leading backslash — an unqualified call here would recurse into
 * the shadow itself.
 *
 * Arming globals:
 *   $GLOBALS['__zef_fake_memory']  = ['fake' => <int bytes>]
 *   $GLOBALS['__zef_usleep_calls'] = []  (appended per delegated call)
 */

namespace Zef\Framework\Runtime;

if (!\function_exists(__NAMESPACE__ . '\memory_get_usage')) {
    function memory_get_usage(bool $real_usage = false): int
    {
        $armed = $GLOBALS['__zef_fake_memory'] ?? null;

        if (\is_array($armed) && \is_int($armed['fake'] ?? null)) {
            return $armed['fake'];
        }

        return \memory_get_usage($real_usage);
    }
}

if (!\function_exists(__NAMESPACE__ . '\usleep')) {
    function usleep(int $microseconds): void
    {
        if (isset($GLOBALS['__zef_usleep_calls']) && \is_array($GLOBALS['__zef_usleep_calls'])) {
            $GLOBALS['__zef_usleep_calls'][] = $microseconds;
        }

        \usleep($microseconds);
    }
}
