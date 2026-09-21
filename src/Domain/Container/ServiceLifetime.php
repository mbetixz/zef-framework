<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

final class ServiceLifetime
{
    public const string SINGLETON = 'singleton';
    public const string REQUEST = 'request';
    public const string TRANSIENT = 'transient';

    public static function assert(string $lifetime): void
    {
        if (!in_array($lifetime, [self::SINGLETON, self::REQUEST, self::TRANSIENT], true)) {
            throw new \InvalidArgumentException("Unknown service lifetime '{$lifetime}'.");
        }
    }
}

// @internal
