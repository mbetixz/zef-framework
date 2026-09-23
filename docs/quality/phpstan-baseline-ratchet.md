# PHPStan Baseline Ratchet — no-new-debt gate

**Gate:** `composer stan:ratchet` → `php scripts/ci/assert-phpstan-baseline.php`
**Wired into:** `.github/workflows/ci.yml` (step *PHPStan baseline ratchet (no-new-debt)*, immediately after the PHPStan analysis step)
**Artefacts:** `phpstan-baseline.neon` (the suppressed findings), `phpstan-baseline.limit` (the frozen ceiling)

---

## Why this gate exists

`phpstan.neon.dist` analyses `src`, `modules`, `plugins` and `tests` at `level: max` with
`phpstan/phpstan-strict-rules` — but it also `includes: phpstan-baseline.neon`.

A baseline makes PHPStan report only the errors *outside* it. At the time of writing the
baseline suppresses **510** findings, and a clean run therefore prints:

```json
{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}
```

`totals.errors = 0` is true and misleading at the same time: the level-max claim holds for the
code the baseline does not cover. The 510 unanswered findings were invisible in CI, and nothing
prevented the number from growing — a contributor could run
`phpstan --generate-baseline`, absorb any number of new errors, and the build would stay green.
That is the debt this gate closes: **the count may go down, never up.**

## What is measured

| Quantity | Source | Meaning |
|---|---|---|
| `entries` | count of `message:` lines inside the `ignoreErrors` block of `phpstan-baseline.neon` | findings currently suppressed |
| `limit` | single integer in `phpstan-baseline.limit` | frozen ceiling |

The scan ignores comments (lines starting with `#`, including the generated-file header) and
requires an actual `ignoreErrors:` block to be present.

## Rules

| Condition | Result |
|---|---|
| `entries <= limit` | **PASS** (exit 0) |
| `entries > limit` | **FAIL** (exit 1), reports the growth |
| `entries < limit` | **PASS**, prints a *ratchet opportunity* — lower `phpstan-baseline.limit` in the same PR to lock the gain |
| baseline missing, no `ignoreErrors` block, or `limit` not a single integer | **FAIL** (exit 1) — fail-closed, never certify an unrecognised format |

A cleanup PR is never blocked by this gate; only growth is.

## Closing the debt (the ratchet procedure)

1. Remove real findings from the code (type the array, narrow the mixed, add the missing generic).
2. Regenerate the baseline **only** for what legitimately remains:
   `vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --memory-limit=1G --generate-baseline=phpstan-baseline.neon`
3. Read the new entry count.
4. Lower `phpstan-baseline.limit` to that number in the **same** PR, and say so in the PR body.
5. The gate confirms the new, lower floor.

The gate is intentionally a *ratchet over text*: every step is a reviewable diff, and the score
cannot move without a deliberate edit.

## Verification (negative control)

A gate that never fails is not a gate. It was proven to fail on an injected violation before
being trusted:

| Probe | Expectation | Observed |
|---|---|---|
| Real baseline, `limit=510` | PASS | `entries: 510, limit: 510, headroom: 0`, exit **0** |
| `limit` forced to `5` | FAIL | `growth: 505`, exit **1** |
| Baseline + 1 synthetic entry, `limit=510` | FAIL | `entries: 511, growth: 1`, exit **1** |
| Baseline with no `ignoreErrors` block | FAIL (format guard) | `refusing to certify an unrecognised format`, exit **1** |

The first implementation of this script counted **0** entries against the real baseline and
reported a vacuous `PASS` — PHPStan nests `message:` inside a sequence item (`-` on its own line,
then indented keys), so an anchored `- message:` pattern never matched. The harness defect was
caught by the negative control, not by the green result; see `docs/reports/`.

## Known limitation

This gate bounds the **number** of suppressed findings, not their severity. 510 findings is a
number to drive down, not a quality claim. Per-release reduction targets are an owner decision.
