<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (inbound adapter helpers)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Router;

/**
 * Route cache: export a Router to a compiled PHP file and restore it later.
 *
 * The compiled artifact is pure data (`<?php return array(...);` produced by
 * var_export), loads with a plain include, and Router::fromCompiledArray()
 * restores a frozen, radix-compiled router without re-validating any route —
 * the cold-start path for large route tables.
 *
 * Write is atomic: temp file in the same directory + rename.
 */
final class RouteCache
{
    public static function export(Router $router): array
    {
        return $router->exportRoutes();
    }

    public static function write(Router $router, string $path): void
    {
        $data = self::export($router);
        $payload = "<?php\n\n// Compiled ZEF route cache — do not edit by hand.\n// Regenerate with RouteCache::write() after every route change.\n\nreturn "
            . var_export($data, true)
            . ";\n";
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true)) {
            throw new \RuntimeException("Cannot create route-cache directory '{$directory}'.");
        }
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write route cache '{$tmp}'.");
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException("Cannot finalize route cache '{$path}'.");
        }
    }

    public static function load(string $path): Router
    {
        if (!is_file($path)) {
            throw new \RuntimeException("Route cache file '{$path}' does not exist.");
        }
        $data = include $path;
        if (!is_array($data)) {
            throw new \RuntimeException("Route cache file '{$path}' did not return an array.");
        }

        return Router::fromCompiledArray($data);
    }
}
