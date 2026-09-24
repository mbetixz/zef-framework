<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Application layer: orchestration,
 * use-case services). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * Orchestrates the configuration pipeline in one deterministic order:
 *
 * 1. merge sources in registration order (LATER sources override EARLIER);
 * 2. resolve `%secret:name%` references through the secrets port;
 * 3. validate against the schema (collect ALL violations);
 * 4. apply declared defaults;
 * 5. wrap the result in an immutable {@see Config} bag.
 *
 * Any violation fails {@see load()} with a single
 * {@see ConfigValidationException} carrying the full report — startup is
 * fail-fast, diagnostics are complete.
 */
final readonly class ConfigLoader
{
    /**
     * Full-value secret reference grammar. Inline fragments are never
     * resolved — only values that are ENTIRELY a reference.
     */
    private const string SECRET_PATTERN = '/^%secret:([A-Za-z0-9._-]{1,128})%$/';

    private const string SOURCE_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_.:-]{0,63}$/';

    /**
     * @param list<ConfigSourceInterface> $sources later sources override earlier
     */
    public function __construct(
        private array $sources,
        private ?SecretsProviderInterface $secrets = null,
        private ?ConfigSchema $schema = null,
    ) {
        $seen = [];
        foreach (array_values($sources) as $source) {
            if (!$source instanceof ConfigSourceInterface) {
                throw new \InvalidArgumentException(
                    'Config sources must implement ConfigSourceInterface, got ' . get_debug_type($source) . '.'
                );
            }
            $name = $source->name();
            if (preg_match(self::SOURCE_NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException("Invalid config source name '{$name}'.");
            }
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException("Duplicate config source name '{$name}'.");
            }
            $seen[$name] = true;
        }
    }

    /**
     * @return list<string>
     */
    public function sourceNames(): array
    {
        return array_map(static fn (ConfigSourceInterface $s): string => $s->name(), array_values($this->sources));
    }

    /**
     * Merged raw values WITHOUT secret resolution, validation or defaults —
     * for diagnostics and tooling; production code consumes load().
     *
     * @return array<array-key,mixed>
     */
    public function raw(): array
    {
        return $this->mergeSources();
    }

    /**
     * @throws ConfigValidationException when secrets are unresolvable or the
     *                                    merged values violate the schema
     */
    public function load(): Config
    {
        $values = $this->mergeSources();
        $violations = [];
        if ($this->secrets instanceof SecretsProviderInterface) {
            $resolved = $this->resolveSecrets($values, '', $violations);

            $values = $resolved;
        }
        if ($this->schema instanceof ConfigSchema) {
            $validator = new ConfigSchemaValidator();
            $violations = [...$violations, ...$validator->validate($values, $this->schema)];
            if ($violations === []) {
                $values = $validator->applyDefaults($values, $this->schema);
            }
        }
        if ($violations !== []) {
            throw new ConfigValidationException($violations);
        }

        return new Config($values);
    }

    /**
     * @return array<array-key,mixed>
     */
    private function mergeSources(): array
    {
        $merged = [];
        foreach (array_values($this->sources) as $source) {
            $values = $source->load();
            if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                throw new InvalidConfigurationException(
                    "Config source '{$source->name()}' must return an associative array."
                );
            }
            $merged = self::mergeTree($merged, self::expandDotted($values));
        }

        return $merged;
    }

    /**
     * Dotted keys are path shorthand — `['database.port' => 5432]` and
     * `['database' => ['port' => 5432]]` are THE SAME configuration. Every
     * source is normalised to a fully nested tree before merging, so both
     * styles combine predictably. A dotted key expands at the level where it
     * is declared (relative to its parent array); within one source, the
     * later declaration wins and expanding a dotted key replaces a scalar
     * standing in its path.
     *
     * @param array<array-key,mixed> $values
     *
     * @return array<array-key,mixed>
     */
    private static function expandDotted(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (is_array($value)) {
                $value = self::expandDotted($value);
            }
            if (str_contains($key, '.')) {
                $out = DottedPaths::set($out, $key, $value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Deterministic deep merge: associative arrays merge recursively, lists
     * and scalars replace wholesale, later side wins on conflicts.
     *
     * @param array<array-key,mixed> $base
     * @param array<array-key,mixed> $override
     *
     * @return array<array-key,mixed>
     */
    private static function mergeTree(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $baseValue = $base[$key] ?? null;
            if (is_array($value) && !array_is_list($value)
                && is_array($baseValue) && !array_is_list($baseValue)) {
                $base[$key] = self::mergeTree($baseValue, $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Replaces `%secret:name%` string leaves; returns one violation per
     * unresolvable reference (tree order, keys sorted per level).
     *
     * @param array<array-key,mixed> $node
     * @param list<ConfigViolation> $violations
     *
     * @return array<array-key,mixed>
     */
    private function resolveSecrets(array $node, string $prefix, array &$violations): array
    {
        $out = [];
        $keys = array_keys($node);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $value = $node[$key];
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out[$key] = $this->resolveSecrets($value, $path, $violations);

                continue;
            }
            if (is_string($value) && preg_match(self::SECRET_PATTERN, $value, $m) === 1) {
                $resolved = $this->secrets?->get($m[1]);
                if ($resolved === null) {
                    $violations[] = new ConfigViolation($path, "references unknown secret '{$m[1]}'");
                    $out[$key] = $value;
                } else {
                    $out[$key] = $resolved;
                }

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }
}
