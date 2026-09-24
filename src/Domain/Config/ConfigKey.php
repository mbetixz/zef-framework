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
 * One schema entry: the declared shape of a dotted configuration key.
 *
 * Construction validates the declaration itself (key grammar, constraint
 * applicability, default/type agreement) so an invalid schema can never be
 * the reason a production boot misbehaves.
 */
final readonly class ConfigKey
{
    /**
     * Dotted key grammar: segments of `[A-Za-z0-9_-]` joined by single dots,
     * 256 characters maximum.
     */
    private const string KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_-]*(\.[A-Za-z0-9][A-Za-z0-9_-]*)*$/';

    public function __construct(
        public string $key,
        public ConfigValueType $type,
        public bool $required = false,
        public mixed $default = null,
        public ?string $enumClass = null,
        public float|int|null $min = null,
        public float|int|null $max = null,
        public ?string $pattern = null,
        public ?string $description = null,
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || strlen($key) > 256) {
            throw new \InvalidArgumentException("Invalid configuration key '{$key}'.");
        }
        if ($type === ConfigValueType::Enum) {
            if ($enumClass === null || !enum_exists($enumClass)
                || !is_subclass_of($enumClass, \BackedEnum::class)) {
                throw new \InvalidArgumentException(
                    "Config key '{$key}' of type enum requires a backed enum class."
                );
            }
        } elseif ($enumClass !== null) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' must be of type enum to declare an enum class."
            );
        }
        if (($min !== null || $max !== null) && $type !== ConfigValueType::Int && $type !== ConfigValueType::Float) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' cannot declare min/max constraints for type '{$type->value}'."
            );
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' declares min greater than max."
            );
        }
        if ($pattern !== null && $type !== ConfigValueType::String) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' cannot declare a pattern for type '{$type->value}'."
            );
        }
        if ($pattern !== null && @preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' declares a pattern that does not compile."
            );
        }
        if ($required && $default !== null) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' is required and cannot declare a default."
            );
        }
        if (!$required && $default !== null && !$type->accepts($default, $enumClass)) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' declares a default that does not match its type: "
                . ConfigValueType::describe($default) . '.'
            );
        }
        if (!$required && $default !== null
            && ($this->isOutOfBounds($default)
                || ($pattern !== null && is_string($default) && preg_match($pattern, $default) !== 1))) {
            throw new \InvalidArgumentException(
                "Config key '{$key}' declares a default outside its declared constraints."
            );
        }
    }

    private function isOutOfBounds(mixed $value): bool
    {
        $numeric = is_int($value) || is_float($value);
        if (!$numeric) {
            return false;
        }

        return ($this->min !== null && $value < $this->min)
            || ($this->max !== null && $value > $this->max);
    }
}
