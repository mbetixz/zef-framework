<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Domain layer: ports, contracts,
 * value objects). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

use Zef\Framework\Exception\InvalidConfigurationException;

/**
 * The typed, immutable configuration bag handed to application code.
 *
 * Built by the loader after merge/secret-resolution/validation/defaults, and
 * registered as a container singleton — handlers and services type-hint it
 * directly. Accessors apply the same strict grammar as the schema validator
 * ({@see ConfigValueType}); a missing or mistyped key is a configuration
 * error and throws {@see InvalidConfigurationException}.
 */
final readonly class Config
{
    /**
     * @param array<array-key,mixed> $values
     */
    public function __construct(private array $values) {}

    /**
     * Full raw tree. Treat as read-only; PHP copy-on-write keeps the bag safe.
     *
     * @return array<array-key,mixed>
     *
     * Top-level keys are string-keyed by source contract; nested levels may
     * contain lists
     */
    public function all(): array
    {
        return $this->values;
    }

    public function has(string $key): bool
    {
        return DottedPaths::has($this->values, $key);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return DottedPaths::has($this->values, $key)
            ? DottedPaths::valueAt($this->values, $key)
            : $default;
    }

    public function string(string $key): string
    {
        $value = $this->typed($key, ConfigValueType::String);
        assert(is_string($value));

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->typed($key, ConfigValueType::Int);
        assert(is_int($value));

        return $value;
    }

    public function float(string $key): float
    {
        $value = $this->typed($key, ConfigValueType::Float);
        assert(is_float($value));

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->typed($key, ConfigValueType::Bool);
        assert(is_bool($value));

        return $value;
    }

    /**
     * @return array<array-key,mixed>
     */
    public function array(string $key): array
    {
        $value = $this->typed($key, ConfigValueType::Array);
        assert(is_array($value));

        return $value;
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    public function enum(string $key, string $enumClass): \BackedEnum
    {
        if (!enum_exists($enumClass) || !is_subclass_of($enumClass, \BackedEnum::class)) {
            throw new \InvalidArgumentException(
                "Config enum accessor for '{$key}' requires a backed enum class."
            );
        }

        $value = $this->typed($key, ConfigValueType::Enum, $enumClass);
        assert($value instanceof \BackedEnum);

        return $value;
    }

    /**
     * Sorted dotted paths of every leaf in the tree.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return DottedPaths::leafPaths($this->values);
    }

    private function typed(string $key, ConfigValueType $type, ?string $enumClass = null): mixed
    {
        if (!DottedPaths::has($this->values, $key)) {
            throw new InvalidConfigurationException("Configuration key '{$key}' is not configured.");
        }
        $raw = DottedPaths::valueAt($this->values, $key);
        if (!$type->accepts($raw, $enumClass)) {
            throw new InvalidConfigurationException(
                "Configuration key '{$key}' expects {$type->value}, got " . ConfigValueType::describe($raw)
            );
        }

        return $type->coerce($raw, $enumClass);
    }
}
