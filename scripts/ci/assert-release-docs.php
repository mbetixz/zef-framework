<?php

declare(strict_types=1);

/**
 * Release docs ratchet — README/SECURITY version references ≤ ZefVersion.
 *
 * WHY THIS EXISTS
 * ---------------
 * Issue #87 (deep-audit at 79ac772): the user-facing docs misstated the
 * released version for nine minors — the README badge said v2.21.0 when the
 * code was v2.28.0, SECURITY.md promised "documented release v2.19.0" and
 * pointed security reporters at the wrong version record. These are the
 * first files a new reader and a security reporter see; a wrong version
 * there is a wrong statement about the supported line. The drift class is
 * the same one the release-cadence gate (issue #88) fixed for tags: a
 * version bump lands and the prose references silently stay behind.
 *
 * WHAT IT ASSERTS (fail-closed)
 * -----------------------------
 *   1. `ZefVersion::VERSION` is extractable from the source of truth;
 *   2. README.md carries the documented-release badge for exactly that
 *      version, the release-history summary block is re-baselined to it,
 *      and the per-release changelog links include it;
 *   3. SECURITY.md names it as the documented release and links its
 *      changelog as the version record.
 *
 * The gate is one-directional like the cadence gate: it pins the docs to
 * the code, never the code to the docs, so a version-bump PR that forgets
 * the docs fails THIS gate in the same PR instead of drifting.
 *
 * EVIDENCE DISCIPLINE
 * -------------------
 * Proven to FAIL before it is trusted: `--version=X.Y.Z` overrides the
 * extracted version for negative controls — running the gate with an
 * override that matches no reference must exit 1 (see the self-check
 * block in the commit that introduced it).
 *
 * Usage: php scripts/ci/assert-release-docs.php [--version=X.Y.Z] [--json]
 */

$root = dirname(__DIR__, 2);

$options = getopt('', ['version::', 'json::']);
$asJson = array_key_exists('json', $options);

/** Fail-closed exit. */
$fail = static function (string $reason, array $context = []) use ($asJson): never {
    if ($asJson) {
        echo json_encode(['status' => 'FAIL', 'reason' => $reason, 'context' => $context], JSON_PRETTY_PRINT), PHP_EOL;
    } else {
        fwrite(STDERR, "RELEASE_DOCS_RATCHET_FAIL: {$reason}\n");
        foreach ($context as $key => $value) {
            fwrite(STDERR, "  {$key}: {$value}\n");
        }
    }
    exit(1);
};

// 1. Source of truth: ZefVersion::VERSION.
$versionFile = $root . '/src/Domain/Foundation/ZefVersion.php';
if (!is_file($versionFile)) {
    $fail('ZefVersion source file not found.', ['path' => $versionFile]);
}
$versionSource = (string) file_get_contents($versionFile);
if (1 !== preg_match("/VERSION\s*=\s*'(\d+\.\d+\.\d+)'/", $versionSource, $m)) {
    $fail('Cannot extract ZefVersion::VERSION from the source of truth.', [
        'file' => 'src/Domain/Foundation/ZefVersion.php',
    ]);
}
$version = is_string($options['version'] ?? null) && '' !== $options['version'] ? (string) $options['version'] : $m[1];

// 2. README.md references.
$readmeFile = $root . '/README.md';
if (!is_file($readmeFile)) {
    $fail('README.md not found.', ['path' => $readmeFile]);
}
$readme = (string) file_get_contents($readmeFile);
$readmeChecks = [
    'documented-release badge' => 'Rilis%20terdokumentasi-v' . $version,
    'release-history summary re-baselined' => 'Riwayat rilis selengkapnya (v2.8.0 → v' . $version . ')',
    'per-release changelog link' => 'CHANGELOG-v' . $version . '.md',
];
foreach ($readmeChecks as $label => $needle) {
    if (!str_contains($readme, $needle)) {
        $fail("README.md is missing the reference for version {$version}.", [
            'check' => $label,
            'expected to contain' => $needle,
        ]);
    }
}

// 3. SECURITY.md references.
$securityFile = $root . '/SECURITY.md';
if (!is_file($securityFile)) {
    $fail('SECURITY.md not found.', ['path' => $securityFile]);
}
$security = (string) file_get_contents($securityFile);
$securityChecks = [
    'supported-versions row' => 'documented release **v' . $version . '**',
    'version-record link' => 'docs/CHANGELOG-v' . $version . '.md',
];
foreach ($securityChecks as $label => $needle) {
    if (!str_contains($security, $needle)) {
        $fail("SECURITY.md is missing the reference for version {$version}.", [
            'check' => $label,
            'expected to contain' => $needle,
        ]);
    }
}

if ($asJson) {
    echo json_encode(['status' => 'OK', 'version' => $version, 'files' => ['README.md', 'SECURITY.md']]), PHP_EOL;
} else {
    echo "RELEASE_DOCS_RATCHET_OK: README.md and SECURITY.md carry version {$version} (matches ZefVersion::VERSION)", PHP_EOL;
}
