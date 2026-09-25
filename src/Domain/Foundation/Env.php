<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * Issue #36 exit ramp: relocated Infrastructure -> Domain with the same
 * FQN and namespace (classmap + PSR-4 multi-directory both resolve it),
 * so the 38 static call sites across layers are untouched. The class is
 * a pure typed reader over process environment state — no side effects,
 * no cross-layer references — matching the getenv()/filter_var() calls
 * the Domain layer already performs directly (see SecurityPolicy).
 *
 * Issue #55: the read-prefixed instance methods below implement
 * EnvInterface (same namespace), bound by the kernel composition root
 * as an ordinary container service. PHP forbids static and instance
 * methods sharing one name, so the historic static surface
 * (`Env::int(...)`, 38 call sites at the time the issue was opened)
 * keeps its exact bodies — the instance surface delegates to them — and
 * the migration can proceed opportunistically per module until the
 * static facade is deprecated and removed in a release-note-worthy
 * final step (issue #55 step 4).
 */

namespace Zef\Framework\Foundation;

/**
 * Typed helpers for reading environment variables.
 * Replaces inline getenv() parsing scattered across the codebase.
 *
 * Two calling conventions over one implementation: the static methods
 * are the historic facade (unchanged signatures, unchanged bodies), the
 * read-prefixed instance methods are the EnvInterface port used by new
 * production code. Zero static state either way.
 */
final class Env implements EnvInterface
{
    /**
     * Read an integer env var, clamping to [$min, $max].
     * When $strict=true, throws if the value is set but invalid (like envIntRequired).
     */
    public static function int(
        string $name,
        int $default,
        int $min,
        int $max,
        bool $strict = false,
    ): int {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
            if ($strict) {
                throw new \InvalidArgumentException($name . ' must be an integer.');
            }

            return $default;
        }
        $v = (int) $raw;
        if ($strict && ($v < $min || $v > $max)) {
            throw new \InvalidArgumentException($name . ' is outside its allowed range.');
        }

        return max($min, min($max, $v));
    }

    public static function bool(string $name, bool $default = false): bool
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    public static function string(string $name, string $default = ''): string
    {
        $raw = getenv($name);

        return ($raw === false || $raw === '') ? $default : $raw;
    }

    /** @return list<string> */
    public static function csv(string $name): array
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        return array_values(
            array_filter(
                array_map(trim(...), explode(',', $raw)),
                static fn (string $v): bool => $v !== '',
            )
        );
    }

    // ---------------------------------------------- EnvInterface surface

    /** @see Env::int() — same body, instance calling convention. */
    #[\Override]
    public function readInt(
        string $name,
        int $default,
        int $min,
        int $max,
        bool $strict = false,
    ): int {
        return self::int($name, $default, $min, $max, $strict);
    }

    /** @see Env::bool() — same body, instance calling convention. */
    #[\Override]
    public function readBool(string $name, bool $default = false): bool
    {
        return self::bool($name, $default);
    }

    /** @see Env::string() — same body, instance calling convention. */
    #[\Override]
    public function readString(string $name, string $default = ''): string
    {
        return self::string($name, $default);
    }

    /** @see Env::csv() — same body, instance calling convention. */
    #[\Override]
    public function readCsv(string $name): array
    {
        return self::csv($name);
    }
}
