<?php

/**
 * ZEF Framework — coverage gate (composer coverage:gate, CI).
 *
 * Parses the PHPUnit clover report and fails when executable-statement
 * coverage drops below the required threshold (default 90%). This is the
 * enforcement mechanism behind the v2.13.0 hardening promise:
 * "all ZEF logic must be PHPUnit-testable, coverage >= 90%".
 *
 * Usage: php scripts/ci/assert-coverage.php [required-percent] [clover-path]
 */

declare(strict_types=1);

$cloverPath = $argv[2] ?? 'build/clover.xml';
$required   = (float) ($argv[1] ?? 90.0);

if (!is_file($cloverPath)) {
    fwrite(STDERR, "coverage gate: clover report not found at {$cloverPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "coverage gate: cannot parse {$cloverPath}\n");
    exit(2);
}

$metrics = $xml->xpath('//project/metrics');
if ($metrics === false || !isset($metrics[0])) {
    fwrite(STDERR, "coverage gate: no project metrics in {$cloverPath}\n");
    exit(2);
}

$total   = (int) $metrics[0]['statements'];
$covered = (int) $metrics[0]['coveredstatements'];

if ($total === 0) {
    fwrite(STDERR, "coverage gate: report contains zero statements\n");
    exit(2);
}

$pct = $covered / $total * 100.0;

printf(
    "Coverage gate: %.2f%% (%d/%d statements) — required: %.1f%%\n",
    $pct,
    $covered,
    $total,
    $required,
);

if ($pct + 1e-9 < $required) {
    fwrite(STDERR, "coverage gate: FAILED\n");
    exit(1);
}

fwrite(STDOUT, "coverage gate: PASSED\n");
exit(0);
