<?php

/**
 * ZEF Framework — abandoned-package audit gate (composer audit).
 *
 * Enforces the composer.json "config.audit.abandoned" policy declared by
 * this project. The gate fails when:
 *
 *  1. the policy key is missing or set to an unknown value, or
 *  2. a package from the known-abandoned watchlist below is required
 *     without acknowledging it via config.audit.ignore-list.
 *
 * Values understood by Composer itself: "ignore" (silent), "report"
 * (warn, exit 0) and "fail" (non-zero exit). ZEF pins "report": upgrades
 * stay visible without breaking CI on third-party renames.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composerPath = $root . '/composer.json';

$fail = static function (string $message): never {
    fwrite(STDERR, "AUDIT FAIL: {$message}\n");
    exit(1);
};

$raw = @file_get_contents($composerPath);
if ($raw === false) {
    $fail("composer.json not readable at {$composerPath}");
}

try {
    $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $fail('composer.json is not valid JSON: ' . $e->getMessage());
}

if (!is_array($composer)) {
    $fail('composer.json did not decode to an object');
}

$policy = $composer['config']['audit']['abandoned'] ?? null;
$known = ['ignore', 'report', 'fail'];
if (!is_string($policy) || !in_array($policy, $known, true)) {
    $fail(sprintf(
        'config.audit.abandoned must be one of [%s], got %s. ZEF policy requires the literal value "report".',
        implode(', ', $known),
        var_export($policy, true),
    ));
}

/*
 * Known-abandoned watchlist: packages that Composer has flagged as
 * abandoned in the past and that this project must not adopt silently.
 * Any hit must be acknowledged under config.audit.ignore-list.
 */
$watchlist = [
    'phpunit/php-token-stream',
    'symfony/monolog-bridge',
    'nommyde/buggregator',
    'sonata-project/exporter',
    'laminas/laminas-zendframework-bridge',
];

$ignoreList = $composer['config']['audit']['ignore-list'] ?? [];
if (!is_array($ignoreList)) {
    $fail('config.audit.ignore-list must be an array of package names');
}

$required = [];
foreach (['require', 'require-dev'] as $section) {
    foreach (array_keys($composer[$section] ?? []) as $name) {
        $required[strtolower((string) $name)] = $section;
    }
}

$hits = [];
foreach ($watchlist as $package) {
    $key = strtolower($package);
    if (isset($required[$key]) && !in_array($package, $ignoreList, true)) {
        $hits[] = sprintf('%s (section: %s)', $package, $required[$key]);
    }
}
if ($hits !== []) {
    $fail('abandoned package(s) required without ignore-list acknowledgement: ' . implode('; ', $hits));
}

fwrite(STDOUT, sprintf(
    "Audit OK: abandoned-policy=%s, %d package(s) checked, 0 unacknowledged watchlist hits.\n",
    $policy,
    count($required),
));
exit(0);
