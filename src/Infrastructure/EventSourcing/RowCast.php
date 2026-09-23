<?php

declare(strict_types=1);

/*
 * ZEF Framework — Event Sourcing (Infrastructure layer: outbound adapters)
 * Added in v2.19.0 (Event Sourcing: event store, projections, snapshots,
 * transactional outbox built on the Database Core port).
 */

namespace Zef\Framework\EventSourcing;

/**
 * Narrowing casts for database rows inside the Event Sourcing adapters.
 *
 * PDO rows arrive as `array<string, mixed>`; every adapter column is a
 * known scalar at runtime, but PHPStan level max (rightly) refuses blind
 * casts on mixed. One place to keep the narrowing rules consistent:
 * numeric columns fall back to 0, string columns to ''.
 */
final class RowCast
{
    private function __construct() {}

    public static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    public static function string(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }

    public static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::string($value);
    }
}
