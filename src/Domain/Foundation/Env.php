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
 * A future instance-based EnvInterface port for composition-root
 * injection remains open on issue #36 and is no longer blocked by the
 * carve-out inventory.
 */

namespace Zef\Framework\Foundation;

/**
 * Typed helpers for reading environment variables.
 * Replaces inline getenv() parsing scattered across the codebase.
 */
final class Env
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
}
