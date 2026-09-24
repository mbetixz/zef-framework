<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 *
 * Issue #36 exit ramp: relocated Application -> Domain with the same
 * FQN and namespace (classmap + PSR-4 multi-directory both resolve it),
 * so every consumer — the Domain security policy aggregator
 * (SecurityPolicy, same namespace) and the middleware origin checks —
 * is untouched. The class is a pure origin-normalisation policy helper:
 * its only dependency is the Domain InvalidConfigurationException, and
 * it performs no I/O, so it satisfies the hexagonal rule the
 * OriginPolicySpec carve-out used to bypass.
 */

namespace Zef\Framework\Security;

use Zef\Framework\Exception\InvalidConfigurationException;

final class OriginPolicy
{
    /** @param list<string> $allowedOrigins */
    public static function assertAllowed(?string $origin, array $allowedOrigins): void
    {
        if ($origin === null || $origin === '') {
            return;
        }
        $normalized = self::normalizeOrigin($origin);
        if (!in_array($normalized, $allowedOrigins, true)) {
            throw new InvalidConfigurationException('Origin is not allowed.');
        }
    }

    public static function normalizeOrigin(string $origin): string
    {
        $origin = trim($origin);
        if ($origin === 'null') {
            return 'null';
        }
        if (preg_match('/[\r\n]/', $origin) === 1) {
            throw new \InvalidArgumentException('Malformed Origin header.');
        }
        $parts = parse_url($origin);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')
        ) {
            throw new \InvalidArgumentException('Malformed Origin header.');
        }
        $scheme = strtolower((string) $parts['scheme']);
        // parse_url keeps IPv6 hosts bracketed ('[::1]'); strip them so
        // the IP / hostname grammar below sees the bare address.
        $host = strtolower((string) $parts['host']);
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Unsupported Origin scheme.');
        }
        if (
            filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match(
                '/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/',
                $host,
            ) !== 1
        ) {
            throw new \InvalidArgumentException('Malformed Origin host.');
        }
        if ($port !== null && $port < 1) {
            throw new \InvalidArgumentException('Malformed Origin port.');
        }
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        return $scheme . '://' . (str_contains($host, ':') ? '[' . $host . ']' : $host)
            . ($port === null ? '' : ':' . $port);
    }
}
