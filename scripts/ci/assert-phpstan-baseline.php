<?php

declare(strict_types=1);

/**
 * PHPStan baseline ratchet — no-new-debt gate.
 *
 * WHY THIS EXISTS
 * ---------------
 * `phpstan.neon.dist` runs at `level: max` with the strict-rules extension but
 * `includes: phpstan-baseline.neon`. That baseline currently suppresses every
 * error it lists, so "PHPStan level max passes" is only true for the code OUTSIDE
 * the baseline: a clean `vendor/bin/phpstan analyse` reports `totals.errors = 0`
 * even while the baseline holds hundreds of unanswered findings. The static-analysis
 * debt is therefore invisible in CI, and there was no mechanism preventing it from
 * growing — a contributor could regenerate the baseline with `--generate-baseline`
 * and silently absorb any number of new errors.
 *
 * WHAT IT ASSERTS (fail-closed)
 * -----------------------------
 *   1. the baseline file exists and is parseable as NEON-ish text;
 *   2. the number of suppressed entries is AT OR BELOW the frozen ceiling in
 *      phpstan-baseline.limit;
 *   3. when the count DROPS below the ceiling the script reports the ratchet
 *      opportunity (the ceiling should be lowered in the same PR) but does not fail,
 *      so a cleanup PR is never blocked by this gate.
 *
 * A baseline that grows beyond the ceiling fails the build. Lowering the debt is the
 * only way the number moves, and every step is a reviewable edit to a text file.
 *
 * EVIDENCE DISCIPLINE
 * -------------------
 * The gate is proven to FAIL on a violation (negative control) before it is trusted;
 * see docs/quality/phpstan-baseline-ratchet.md.
 *
 * Usage: php scripts/ci/assert-phpstan-baseline.php [--limit-file=PATH] [--baseline=PATH]
 */

$root = dirname(__DIR__, 2);
$options = getopt('', ['limit-file::', 'baseline::', 'json::']);

$baselinePath = (string) ($options['baseline'] ?? $root . '/phpstan-baseline.neon');
$limitPath = (string) ($options['limit-file'] ?? $root . '/phpstan-baseline.limit');
$asJson = array_key_exists('json', $options);

/** Fail-closed exit. */
$fail = static function (string $reason, array $context = []) use ($asJson): never {
    if ($asJson) {
        echo json_encode(['status' => 'FAIL', 'reason' => $reason, 'context' => $context], JSON_PRETTY_PRINT), PHP_EOL;
    } else {
        fwrite(STDERR, "PHPSTAN_BASELINE_RATCHET_FAIL: {$reason}\n");
        foreach ($context as $key => $value) {
            fwrite(STDERR, "  {$key}: {$value}\n");
        }
    }
    exit(1);
};

if (!is_file($baselinePath)) {
    $fail('baseline file not found', ['baseline' => $baselinePath]);
}

if (!is_file($limitPath)) {
    $fail('limit file not found', ['limit' => $limitPath]);
}

$limitRaw = trim((string) file_get_contents($limitPath));
if ($limitRaw === '' || !preg_match('/^\d+$/', $limitRaw)) {
    $fail('limit file must contain a single non-negative integer', ['content' => $limitRaw]);
}
$limit = (int) $limitRaw;

$baseline = (string) file_get_contents($baselinePath);

// Count suppressed entries. PHPStan's generated baseline lists one `message:` per
// ignored finding inside the `ignoreErrors` sequence; `path:` lines and comments
// (the `#` header block) are not entries.
$entries = 0;
$hasIgnoreErrors = false;
foreach (preg_split('/\R/', $baseline) ?: [] as $line) {
    $trimmed = ltrim($line);
    if (str_starts_with($trimmed, '#')) {
        continue;
    }
    if (preg_match('/^\s*ignoreErrors:\s*$/', $line) === 1) {
        $hasIgnoreErrors = true;
        continue;
    }
    if (preg_match('/^\s*message:\s*\S/u', $line) === 1) {
        $entries++;
    }
}

if (!$hasIgnoreErrors) {
    $fail('baseline has no ignoreErrors block — refusing to certify an unrecognised format', ['baseline' => $baselinePath]);
}

if ($entries > $limit) {
    $fail(
        'baseline grew beyond the frozen ceiling — suppress fewer errors, or justify the increase in review',
        [
            'entries' => $entries,
            'limit' => $limit,
            'growth' => $entries - $limit,
            'baseline' => $baselinePath,
        ]
    );
}

$remaining = $limit - $entries;
$result = [
    'status' => 'PASS',
    'entries' => $entries,
    'limit' => $limit,
    'headroom' => $remaining,
    'ratchet_opportunity' => $remaining > 0,
];

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
} else {
    printf(
        "PHPSTAN_BASELINE_RATCHET_OK: %d suppressed entries (ceiling %d, headroom %d)%s\n",
        $entries,
        $limit,
        $remaining,
        $remaining > 0 ? ' — lower phpstan-baseline.limit in this PR to lock the gain' : ''
    );
}

exit(0);
