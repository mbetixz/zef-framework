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
 * Internal dotted-path navigation over nested configuration trees.
 *
 * All operations are deterministic: leaf enumeration sorts keys at every
 * level before walking, so reports never depend on insertion order.
 */
final class DottedPaths
{
    /**
     * @param array<array-key,mixed> $values
     */
    public static function has(array $values, string $path): bool
    {
        $node = $values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return false;
            }
            $node = $node[$segment];
        }

        return true;
    }

    /**
     * @param array<array-key,mixed> $values
     */
    public static function valueAt(array $values, string $path): mixed
    {
        $node = $values;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                throw new \LogicException("Path '{$path}' does not exist; call has() first.");
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * Every leaf path of the tree. Empty arrays count as leaves so strict
     * schemas can still reject unknown keys whose value happens to be empty.
     *
     * @param array<array-key,mixed> $values
     *
     * @return list<string>
     */
    public static function leafPaths(array $values): array
    {
        $paths = [];
        self::walk($values, '', $paths);

        return $paths;
    }

    /**
     * Sets a value at a dotted path, creating intermediate arrays.
     *
     * @param array<array-key,mixed> $values
     *
     * @return array<array-key,mixed>
     */
    public static function set(array $values, string $path, mixed $value): array
    {
        $segments = explode('.', $path);
        $head = array_shift($segments);
        if ($segments === []) {
            $values[$head] = $value;

            return $values;
        }
        $child = $values[$head] ?? [];
        if (!is_array($child)) {
            $child = [];
        }
        $values[$head] = self::set($child, implode('.', $segments), $value);

        return $values;
    }

    /**
     * @param array<array-key,mixed> $values
     * @param list<string> $out
     */
    private static function walk(array $values, string $prefix, array &$out): void
    {
        $sorted = $values;
        ksort($sorted);
        foreach ($sorted as $k => $v) {
            $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
            if (is_array($v) && $v !== []) {
                self::walk($v, $path, $out);

                continue;
            }
            $out[] = $path;
        }
    }
}
