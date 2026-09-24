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
 * Writes the validated configuration to a pure-PHP file for production
 * boots: {@see CompiledConfigSource} then loads it with zero source parsing,
 * zero environment reads and zero secret lookups.
 *
 * Export is atomic (temp file + rename) and refuses values that cannot be
 * represented — only scalars, nulls and arrays of those ever reach the file.
 */
final class ConfigCompiler
{
    public function export(Config $config, string $targetFile): void
    {
        $directory = dirname($targetFile);
        $basename = basename($targetFile);
        if (in_array($basename, ['', '.', '..'], true)) {
            throw new InvalidConfigurationException("Invalid config compile target '{$targetFile}'.");
        }
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new InvalidConfigurationException(
                "Config compile target directory is not writable: '{$directory}'."
            );
        }
        $values = $config->all();
        $unexportable = self::firstUnexportable($values, '');
        if ($unexportable !== null) {
            throw new InvalidConfigurationException(
                "Config value at '{$unexportable}' cannot be compiled (only scalars, nulls and arrays are exportable)."
            );
        }
        $code = "<?php\n\ndeclare(strict_types=1);\n\n"
            . "/* Compiled application configuration (ZEF Framework v2.21.0). Do not edit. */\n\n"
            . 'return ' . var_export($values, true) . ";\n";
        $tmp = $directory . '/.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($tmp, $code) === false) {
            throw new InvalidConfigurationException("Failed to write compiled config temp file '{$tmp}'.");
        }
        if (!@rename($tmp, $targetFile)) {
            // Cleanup of $tmp, a name this method generated itself
            // ('.' . $basename . '.' . bin2hex(random_bytes(6)) . '.tmp', line 48). No
            // request input reaches the argument; this runs only when the rename
            // immediately above failed. Registered as an accepted suppression:
            // docs/security/php-sast.md §7.
            @unlink($tmp); // nosemgrep: php.lang.security.unlink-use

            throw new InvalidConfigurationException("Failed to publish compiled config '{$targetFile}'.");
        }
    }

    /**
     * @param array<array-key,mixed> $values
     */
    private static function firstUnexportable(array $values, string $prefix): ?string
    {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                /** @var array<string,mixed> $value */
                $nested = self::firstUnexportable($value, $path);
                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }
            if ($value !== null && !is_scalar($value)) {
                return $path;
            }
        }

        return null;
    }
}
