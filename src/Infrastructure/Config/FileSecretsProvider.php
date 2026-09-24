<?php

declare(strict_types=1);

/*
 * ZEF Framework — Configuration System v2 (Infrastructure layer: outbound
 * adapters). Added in v2.21.0 (multi-source application configuration:
 * PHP file sources, environment overlay, secrets provider, schema-validated
 * typed accessors with fail-fast startup).
 */

namespace Zef\Framework\Config;

/**
 * File-backed secrets provider — one plain file per secret inside a single
 * directory, the Docker / Kubernetes secret-mount convention.
 *
 * The key grammar (`^[a-z0-9][a-z0-9._-]{0,127}$`, no `..`, no slashes)
 * makes path traversal structurally impossible; a key outside the grammar
 * is simply unknown. File contents are trimmed of surrounding whitespace.
 * Empty files are valid secrets (empty string), distinct from unknown keys
 * (null).
 */
final readonly class FileSecretsProvider implements SecretsProviderInterface
{
    private const string KEY_PATTERN = '/^[a-z0-9][a-z0-9._-]{0,127}$/';

    public function __construct(private string $directory)
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException("Secrets directory '{$directory}' does not exist.");
        }
    }

    #[\Override]
    public function get(string $key): ?string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1 || str_contains($key, '..')) {
            return null;
        }
        $file = $this->directory . '/' . $key;
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $contents = file_get_contents($file);

        return $contents === false ? null : trim($contents);
    }
}
