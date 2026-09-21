<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Infrastructure layer (outbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
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
