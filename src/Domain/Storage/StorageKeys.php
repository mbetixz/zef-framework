<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.30.0 — Domain layer (ports, contracts, value objects)
 * Ecosystem Ports: the single validation authority for storage keys.
 */

namespace Zef\Framework\Storage;

/**
 * Pure key/prefix validation shared by every ObjectStorageInterface adapter.
 *
 * Centralising the grammar here is the path-safety backstop: backends (the
 * Local filesystem adapter especially) MUST route every key through
 * assertValidKey()/assertValidPrefix(), so traversal segments can never be
 * smuggled into a backend path regardless of the adapter's own resolution
 * logic.
 *
 * Grammar:
 * - 1..1024 bytes (prefix: 0..1024, empty prefix = root listing);
 * - UTF-8 NOT required — byte-transparent like S3 keys, but NUL and other
 *   C0 control characters are rejected;
 * - slash-separated segments; empty segments (`//`), leading and trailing
 *   slashes are rejected (a prefix MAY end with exactly one slash);
 * - the segments `.` and `..` are rejected (traversal);
 * - backslash is rejected — keys must be portable across POSIX and Windows
 *   filesystems without encoding ambiguity.
 */
final class StorageKeys
{
    public const int MAX_BYTES = 1024;

    public static function assertValidKey(string $key): void
    {
        self::assertSegments($key, 'Storage key', allowEmpty: false, allowTrailingSlash: false);
    }

    public static function assertValidPrefix(string $prefix): void
    {
        self::assertSegments($prefix, 'Storage prefix', allowEmpty: true, allowTrailingSlash: true);
    }

    private static function assertSegments(
        string $value,
        string $label,
        bool $allowEmpty,
        bool $allowTrailingSlash,
    ): void {
        if ($value === '') {
            if ($allowEmpty) {
                return;
            }

            throw new \InvalidArgumentException($label . ' cannot be empty.');
        }
        if (strlen($value) > self::MAX_BYTES) {
            throw new \InvalidArgumentException($label . ' exceeds ' . self::MAX_BYTES . ' bytes.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException($label . ' must not contain control characters.');
        }
        if (str_contains($value, '\\')) {
            throw new \InvalidArgumentException($label . ' must not contain backslashes.');
        }
        if (str_starts_with($value, '/')) {
            throw new \InvalidArgumentException($label . ' must not start with a slash.');
        }
        if (!$allowTrailingSlash && str_ends_with($value, '/')) {
            throw new \InvalidArgumentException($label . ' must not end with a slash.');
        }
        // No bare-slash ambiguity is possible here: a leading slash was
        // rejected above and an empty normalization target ('/') cannot
        // reach this point, so the segment loop below always sees input.
        $normalized = $allowTrailingSlash && str_ends_with($value, '/')
            ? substr($value, 0, -1)
            : $value;
        foreach (explode('/', $normalized) as $segment) {
            if (in_array($segment, ['', '.', '..'], true)) {
                throw new \InvalidArgumentException(
                    $label . " contains an invalid path segment ({$segment}).",
                );
            }
        }
    }
}
