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
 * Immutable collection of {@see ConfigKey} declarations.
 *
 * With `allowUnknownKeys = false` (strict mode) the validator also reports
 * any configuration leaf that is not declared here — catching typos like
 * `databse.host` at boot instead of at 3 AM in production.
 */
final readonly class ConfigSchema
{
    /**
     * Normalized list of declared keys (input array keys are discarded).
     *
     * @var list<ConfigKey>
     */
    public array $keys;

    /**
     * @var array<string,ConfigKey>
     */
    private array $byKey;

    /**
     * @param array<array-key,ConfigKey> $keys
     */
    public function __construct(array $keys, public bool $allowUnknownKeys = true)
    {
        /** @var list<ConfigKey> $normalized */
        $normalized = array_values($keys);
        $this->keys = $normalized;
        $byKey = [];
        foreach ($normalized as $key) {
            if (isset($byKey[$key->key])) {
                throw new \InvalidArgumentException("Duplicate schema key '{$key->key}'.");
            }
            $byKey[$key->key] = $key;
        }
        $this->byKey = $byKey;
    }

    public static function of(ConfigKey ...$keys): self
    {
        return new self($keys);
    }

    public function key(string $dottedKey): ?ConfigKey
    {
        return $this->byKey[$dottedKey] ?? null;
    }
}
