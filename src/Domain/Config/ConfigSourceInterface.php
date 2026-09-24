<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Application\Config\ConfigLoader;

/**
 * Outbound port: one named origin of raw configuration values.
 *
 * Sources are registered in order on {@see ConfigLoader};
 * the merged view lets LATER sources override EARLIER ones (environment overlay
 * wins over file defaults, local overrides win over both).
 *
 * Implementations must be deterministic for the lifetime of a boot: the same
 * load() call sequence at boot time must always produce the same shape.
 */
interface ConfigSourceInterface
{
    /**
     * Stable identifier used in diagnostics and validation reports.
     * Grammar: `^[A-Za-z][A-Za-z0-9_.:-]{0,63}$` (built-in adapters use
     * `file:`, `env:` and `compiled:` prefixes).
     */
    public function name(): string;

    /**
     * Raw nested configuration tree. Top level MUST be an associative
     * array of string keys; leaves are scalars or arrays.
     *
     * @return array<string,mixed>
     */
    public function load(): array;
}
