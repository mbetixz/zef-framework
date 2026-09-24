<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

/**
 * Outbound port: resolves a secret reference to its secret value.
 *
 * Config values written as `%secret:name%` are resolved through this port by
 * the configuration loader; an unresolvable name becomes a validation
 * violation, never a silent empty string. Implementations must not log
 * secret material.
 */
interface SecretsProviderInterface
{
    /**
     * Returns the secret stored under `key`, or null when the key is unknown.
     *
     * Key grammar (shared by adapters): `^[a-z0-9][a-z0-9._-]{0,127}$` with no
     * `..` sequence — the grammar itself makes path traversal impossible.
     */
    public function get(string $key): ?string;
}
