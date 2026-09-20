#!/usr/bin/env bash
#
# Mago preflight - SECONDARY, ADVISORY, NON-BLOCKING static-quality signal.
# ---------------------------------------------------------------------------
# THIS SCRIPT ALWAYS EXITS 0. That is the whole point of it.
#
# Mago is NOT a quality authority in this repository and must not silently
# become one. It has not been audited against the existing authorities, so its
# output is collected, reported and annotated - never used to fail a build.
#
# Authority map (unchanged by this script):
#   PHP-CS-Fixer -> formatting        PHPStan   -> static / type analysis
#   Deptrac      -> architecture      PHPUnit   -> runtime behaviour
#   Semgrep      -> security patterns Gitleaks  -> secrets
#   Mago         -> additional signal only
#
# Conflict rule (deliberate, NOT a majority vote): if Mago and PHPStan disagree
# on whether the tree is clean, the result is recorded as CONFLICTED / needs
# investigation. Neither tool is allowed to override the other by weight of
# numbers, and a disagreement is never silently resolved in Mago's favour or
# against it.
#
# Promotion path: making any Mago output blocking requires an owner decision
# plus an audit of its actual findings - see the tooling adoption report.

set -uo pipefail  # NOTE: no -e. A failing signal must not abort the report.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

SUMMARY_FILE="${GITHUB_STEP_SUMMARY:-}"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

mago_field() {
  # $1 = file holding Mago JSON output (possibly followed by trailing text)
  python3 - "$1" <<'PY'
import json
import sys

path = sys.argv[1]
try:
    raw = open(path, encoding="utf-8", errors="replace").read()
except OSError:
    print("-")
    raise SystemExit

start = raw.find("{")
if start < 0:
    print("-")
    raise SystemExit

try:
    obj, _ = json.JSONDecoder().raw_decode(raw[start:])
except ValueError:
    print("-")
    raise SystemExit

issues = obj.get("issues")
if not isinstance(issues, list):
    print("-")
    raise SystemExit

codes = []
for issue in issues:
    if isinstance(issue, dict):
        code = issue.get("code")
        if isinstance(code, str) and code not in codes:
            codes.append(code)

print(f"{len(issues)}|{','.join(codes)}" if codes else str(len(issues)))
PY
}

phpstan_errors() {
  python3 - "$1" <<'PY'
import json
import sys

path = sys.argv[1]
try:
    data = json.load(open(path, encoding="utf-8", errors="replace"))
except (OSError, ValueError):
    print("-")
    raise SystemExit

totals = data.get("totals") or {}
errors = totals.get("errors")
file_errors = totals.get("file_errors")
if not isinstance(errors, int) or not isinstance(file_errors, int):
    print("-")
    raise SystemExit

print(errors + file_errors)
PY
}

emit() {
  printf '%s\n' "$*"
  if [ -n "$SUMMARY_FILE" ]; then
    printf '%s\n' "$*" >> "$SUMMARY_FILE"
  fi
}

# --- signals ---------------------------------------------------------------
mago_analyze_rc="-"
mago_lint_rc="-"
mago_format_rc="-"

if [ -x vendor/bin/mago ]; then
  vendor/bin/mago analyze --reporting-format json > "$tmpdir/mago-analyze.json" 2>&1
  mago_analyze_rc=$?

  vendor/bin/mago lint --reporting-format json > "$tmpdir/mago-lint.json" 2>&1
  mago_lint_rc=$?

  vendor/bin/mago format --check > "$tmpdir/mago-format.txt" 2>&1
  mago_format_rc=$?
else
  echo "Mago not installed; skipping advisory signals." > "$tmpdir/mago-analyze.json"
  : > "$tmpdir/mago-lint.json"
  : > "$tmpdir/mago-format.txt"
fi

mago_analyze="$(mago_field "$tmpdir/mago-analyze.json")"
mago_lint="$(mago_field "$tmpdir/mago-lint.json")"

if [ -x vendor/bin/phpstan ]; then
  vendor/bin/phpstan analyse \
    --configuration=phpstan.neon.dist \
    --error-format=json \
    --no-progress \
    > "$tmpdir/phpstan.json" 2>&1
  phpstan_rc=$?
  phpstan_count="$(phpstan_errors "$tmpdir/phpstan.json")"
else
  phpstan_rc="-"
  phpstan_count="-"
fi

mago_count="${mago_analyze%%|*}"

# --- conflict classification (no majority vote) ----------------------------
if [ "$mago_count" = "-" ] || [ "$phpstan_count" = "-" ]; then
  verdict="UNKNOWN"
  verdict_note="one or both signals could not be measured; no conflict can be claimed from missing data"
elif [ "$mago_count" = "0" ] && [ "$phpstan_count" = "0" ]; then
  verdict="AGREE_CLEAN"
  verdict_note="both report a clean tree"
elif [ "$mago_count" != "0" ] && [ "$phpstan_count" != "0" ]; then
  verdict="AGREE_ISSUES"
  verdict_note="both report issues; PHPStan remains the authority for the verdict"
else
  verdict="CONFLICTED"
  verdict_note="the two analyzers DISAGREE - needs investigation, never a majority vote"
fi

# --- report ----------------------------------------------------------------
emit "### Mago preflight (advisory, non-blocking)"
emit ""
emit "This job cannot fail the build and is not a required status check."
emit "Mago is an additional signal only; PHPStan is the static-analysis authority."
emit ""
emit "| Signal | Result | Exit |"
emit "| --- | --- | --- |"
emit "| mago analyze | ${mago_analyze} | ${mago_analyze_rc} |"
emit "| mago lint | ${mago_lint} | ${mago_lint_rc} |"
emit "| mago format --check | see log | ${mago_format_rc} |"
emit "| phpstan (authority) | ${phpstan_count} error(s) | ${phpstan_rc} |"
emit ""
emit "**Cross-tool verdict: \`${verdict}\`** - ${verdict_note}"
emit ""

if [ "$verdict" = "CONFLICTED" ]; then
  emit "Mago and PHPStan disagree on this revision. Per tooling policy this is recorded as"
  emit "CONFLICTED rather than resolved by counting votes: a Mago-only finding may be a real"
  emit "defect PHPStan cannot see, or a Mago false positive. Investigate before acting."
fi

if [ "$mago_format_rc" = "8" ]; then
  emit ""
  emit "NOTE: Mago's formatter wants changes, but \`PHP-CS-Fixer\` decides formatting in this"
  emit "repository. Do not run \`mago format\` to make this number go away."
fi

emit ""
emit "Raw Mago output is in this job's log. No Mago output is used to gate anything."

exit 0
