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
 * One configuration problem collected by {@see ConfigSchemaValidator}.
 * Violations are accumulated so a failed startup reports EVERY problem at
 * once instead of forcing fix-one-reboot cycles.
 */
final readonly class ConfigViolation implements \Stringable
{
    public function __construct(
        public string $key,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return $this->key . ': ' . $this->message;
    }
}
