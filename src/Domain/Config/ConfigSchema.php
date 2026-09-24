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
 *
 * Since v2.23.0 (issue #60 P4) the schema carries an integer `version`
 * (default {@see CURRENT_VERSION}); when incoming configuration data is
 * stamped with an older version, the loader pipeline runs registered
 * {@see ConfigMigrator} steps in order before validating.
 */
final readonly class ConfigSchema
{
    /**
     * Version of the schema format the current framework release expects.
     * Bump ONLY on a real schema-breaking change and register a migration
     * step for every hop (see docs/CHANGELOG-v2.23.0.md).
     */
    public const int CURRENT_VERSION = 1;

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
     * @param int $version               schema format version this
     *                                    declaration targets (>= 1)
     */
    public function __construct(
        array $keys,
        public bool $allowUnknownKeys = true,
        public int $version = self::CURRENT_VERSION,
    ) {
        if ($version < 1) {
            throw new \InvalidArgumentException(
                'Config schema version must be a positive integer, got ' . $version . '.'
            );
        }

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
