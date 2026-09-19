#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Abandoned-dependency policy gate (fail-closed).
 *
 * Composer's `--abandoned=fail` is an all-or-nothing switch: a single transitive
 * abandoned dev dependency turns the whole security-audit step red, which hides
 * real advisories behind a known, accepted exception. This gate replaces that
 * switch with an explicit, time-boxed allowlist so that:
 *
 *   1. security advisories remain a hard failure (defence in depth — this gate
 *      fails closed if any advisory is present, even though the audit step
 *      already checks them);
 *   2. every abandoned package is either listed in scripts/ci/abandoned-allowlist.json
 *      or the build fails;
 *   3. every allowlist entry carries an expiry date and the build fails once it
 *      lapses, so an exception can never become permanent silently;
 *   4. a stale entry (listed but no longer abandoned) also fails, so the
 *      allowlist cannot rot.
 *
 * Exit codes: 0 = policy satisfied, 1 = policy violated (fail-closed).
 */

$root = dirname(__DIR__, 2);
$allowlistPath = __DIR__ . '/abandoned-allowlist.json';

/**
 * @param string $message
 * @return never
 */
function fail(string $message): void
{
    fwrite(STDERR, "ABANDONED-POLICY: FAIL - {$message}\n");
    exit(1);
}

if (!is_file($allowlistPath)) {
    fail("allowlist not found at {$allowlistPath}");
}

$allowlistRaw = file_get_contents($allowlistPath);
if ($allowlistRaw === false) {
    fail("allowlist at {$allowlistPath} is not readable");
}

try {
    /** @var array<string, mixed> $allowlist */
    $allowlist = json_decode($allowlistRaw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fail("allowlist is not valid JSON: {$e->getMessage()}");
}

$allowed = [];
foreach (($allowlist['allowed'] ?? []) as $entry) {
    if (!is_array($entry) || !isset($entry['name'], $entry['expires'])) {
        fail('every allowlist entry must declare "name" and "expires"');
    }

    $name = (string) $entry['name'];
    $expires = (string) $entry['expires'];

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
    if ($date === false || $date->format('Y-m-d') !== $expires) {
        fail("allowlist entry \"{$name}\" has a malformed expires date (expected YYYY-MM-DD, got \"{$expires}\")");
    }

    if ($date < new DateTimeImmutable('today')) {
        fail("allowlist entry \"{$name}\" EXPIRED on {$expires} - re-review the dependency or remove it");
    }

    $allowed[$name] = $expires;
}

if ($allowed === []) {
    fail('allowlist declares no entries; use --abandoned=fail directly instead of this gate');
}

$command = 'composer audit --locked --abandoned=report --format=json 2>/dev/null';
$output = shell_exec(sprintf('cd %s && %s', escapeshellarg($root), $command));

if (!is_string($output) || trim($output) === '') {
    fail('composer audit produced no output (is composer installed and the lock file present?)');
}

try {
    /** @var array<string, mixed> $audit */
    $audit = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fail("composer audit output is not valid JSON: {$e->getMessage()}");
}

/** @var array<string, mixed> $advisories */
$advisories = $audit['advisories'] ?? [];
if ($advisories !== []) {
    fail(sprintf('security advisories present (%d package(s)) - fix or formally accept them first', count($advisories)));
}

/** @var array<string, string|null> $abandoned */
$abandoned = $audit['abandoned'] ?? [];

$violations = [];

foreach (array_keys($abandoned) as $package) {
    if (!isset($allowed[$package])) {
        $violations[] = "abandoned package \"{$package}\" is not in the allowlist";
    }
}

foreach (array_keys($allowed) as $package) {
    if (!array_key_exists($package, $abandoned)) {
        $violations[] = "allowlist entry \"{$package}\" is stale - it is no longer reported as abandoned, remove it";
    }
}

if ($violations !== []) {
    foreach ($violations as $violation) {
        fwrite(STDERR, "ABANDONED-POLICY: {$violation}\n");
    }

    fail(sprintf('%d violation(s)', count($violations)));
}

echo "ABANDONED-POLICY: OK\n";
echo "  security advisories: 0\n";
echo sprintf("  abandoned packages accepted: %d\n", count($abandoned));

foreach ($abandoned as $package => $replacement) {
    echo sprintf(
        "    - %s (allowlisted until %s; replacement: %s)\n",
        $package,
        $allowed[$package],
        is_string($replacement) && $replacement !== '' ? $replacement : 'none suggested',
    );
}

exit(0);
