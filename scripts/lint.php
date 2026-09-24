<?php

/**
 * ZEF Framework — lint every first-party PHP source file (php -l).
 *
 * Usage:
 *   php scripts/lint.php
 *   composer lint
 *
 * First-party only: vendor/, build/ and tool cache directories are
 * skipped so the gate reflects the state of the code this project owns.
 * The php binary is resolved through PHP_BINARY so the script works
 * regardless of PATH.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$skipDirs = [
    $root . '/vendor',
    $root . '/build',
    $root . '/.phpunit.cache',
    $root . '/.php-cs-fixer.cache',
    $root . '/build/api-cache',
];

$iterate = static function (string $dir) use ($root, $skipDirs): \Generator {
    $flags = \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME;
    $objects = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, $flags),
        \RecursiveIteratorIterator::LEAVES_ONLY,
    );
    /** @var string $path */
    foreach ($objects as $path) {
        foreach ($skipDirs as $skip) {
            if (str_starts_with($path, $skip . '/')) {
                continue 2;
            }
        }
        if (str_ends_with($path, '.php')) {
            yield substr($path, strlen($root) + 1);
        }
    }
};

$failures = 0;
$checked  = 0;
$php = \PHP_BINARY;
foreach ($iterate($root) as $file) {
    $checked++;
    // False positive on argument shape, not provenance: `$php` is \PHP_BINARY (the running
    // interpreter's own path) and $file is a relative path yielded by the directory walker above,
    // escaped with escapeshellarg(); no request-derived value reaches the command.
    // Registered in docs/security/php-sast.md §7.5.
    exec('' . $php . ' -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $out, $code); // nosemgrep: exec-use
    if ($code !== 0) {
        $failures++;
        fwrite(STDERR, implode("\n", $out) . "\n");
    }
    $out = [];
}

fwrite(STDOUT, "Linted {$checked} PHP files — {$failures} failure(s).\n");
exit($failures === 0 ? 0 : 1);
