<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Adapters layer (inbound adapters)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Http;

/**
 * Shared CIDR-matching logic extracted from RequestFactory and
 * ClientAddressResolver to eliminate duplication.
 */
final class TrustedProxyMatcher
{
    /** @param list<string> $trusted */
    public static function matches(string $ip, array $trusted): bool
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        foreach ($trusted as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if ($entry === $ip) {
                return true;
            }
            if (!str_contains($entry, '/')) {
                continue;
            }
            [$network, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
            if ($prefix === null || !ctype_digit($prefix)) {
                continue;
            }
            if (self::ipInCidr($ip, (string) $network, (int) $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function ipInCidr(string $ip, string $network, int $prefix): bool
    {
        $ipBin = @inet_pton($ip);
        $networkBin = @inet_pton($network);
        if ($ipBin === false || $networkBin === false || strlen($ipBin) !== strlen($networkBin)) {
            return false;
        }
        $maxPrefix = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return false;
        }
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($networkBin, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($networkBin[$fullBytes]) & $mask);
    }
}

/*
 * Static factory for JSON error responses.
 * Eliminates hand-built JSON strings scattered across the codebase.
 */
