#!/usr/bin/env bash
#
# Mago quality gate - BLOCKING static-quality gate.
# ---------------------------------------------------------------------------
# THIS SCRIPT FAILS CLOSED. It exits 1 whenever the Mago signal cannot be
# established as clean, and exits 0 only when Mago measures clean.
#
# OPERATOR PROCEDURE - A RED Mago GATE
#   "Mago Quality Gate" is a required status check on `main`, so a red run blocks
#   the merge. A red run means one of two different things. Read the summary table
#   the script writes before acting - never assume which one it is:
#
#   A. The gate MEASURED A MAGO PROBLEM (blocking reasons name a count > 0).
#      This is the gate working as intended. Fix the code. Do NOT look for a
#      bypass: bypassing a true positive is how the gate stops meaning anything.
#
#   B. The gate COULD NOT BE EVALUATED ("the gate cannot be evaluated" appears in
#      the blocking reasons, or the seed step failed). This is a provisioning or
#      network fault, not a finding about the change: a seed download failure, an
#      unavailable release, a runner-image change, or `vendor/bin/mago` absent.
#      The change itself has NOT been shown to be bad, and it has also NOT been
#      shown to be good - the gate is simply blind on that run.
#
#      For case B only, the documented recourse is the GitHub branch-protection
#      admin bypass (a repository admin with "Allow specified actors to bypass
#      required pull requests" / the classic admin-enforcement switch merges the
#      pull request explicitly). Requirements, all mandatory:
#        * only a repository ADMIN may do it, and only for case B;
#        * the pull request must record WHY in a comment before merging, naming
#          the failing step and the provisioning fault;
#        * the bypass must be visible in the repository audit log - a silent or
#          unattributed bypass is indistinguishable from a weakened gate;
#        * re-run the gate on the next pull request; a recurring case B is a bug
#          to fix, not a standing exception;
#        * never "fix" case B by re-adding `continue-on-error`, by making this
#          script exit 0 again, or by removing the context from the required list.
#      Bypassing case A is out of procedure and must not be done.
#
# PROMOTION HISTORY - why this file no longer says "always exits 0".
#   This script was introduced as an ADVISORY collector whose header stated, in
#   terms, that "THIS SCRIPT ALWAYS EXITS 0. That is the whole point of it."
#   That is no longer true and has been replaced. The promotion to a blocking
#   gate was an explicit owner decision; the old contract is recorded here
#   because it is the only thing that explains several design choices below.
#
# WHAT THE GATE DECIDES (fail-closed)
#   FAIL - exit 1 - when ANY of the following holds:
#     * `vendor/bin/mago` is absent or not executable  -> gate cannot be evaluated
#     * `mago analyze` produced no parsable result     -> gate cannot be evaluated
#     * `mago analyze` reported >= 1 issue
#     * `mago lint` reported >= 1 issue
#     * `mago format --check` did not report a clean tree
#   PASS - exit 0 - only when every Mago signal above is measured AND clean.
#
#   "Cannot be evaluated" is a FAILURE, not a skip. A gate that turned into a
#   silent pass when its own tool is missing would be worse than no gate, so the
#   absent-tool case is deliberately on the failing side of the decision.
#
# WHAT IT DELIBERATELY DOES NOT DECIDE
#   PHPStan. PHPStan remains the static/type-analysis authority and is already
#   enforced by the required status check "PHP lint, audit, static analysis and
#   style" (composer stan). This gate does not duplicate it: a problem that only
#   PHPStan sees is reported as CONFLICTED and is NOT a Mago gate failure,
#   because the existing required check already owns that direction. A
#   disagreement is still never resolved by a majority vote.
#
# Authority map (unchanged by this script):
#   PHP-CS-Fixer -> formatting        PHPStan   -> static / type analysis
#   Deptrac      -> architecture      PHPUnit   -> runtime behaviour
#   Semgrep      -> security patterns Gitleaks  -> secrets
#   Mago         -> blocking static-quality gate (this script)
#
# WHY `set -uo pipefail` WITHOUT `-e`
#   Deliberate, and it is NOT a suppression. With `-e` the first non-zero command
#   would abort the script mid-report and truncate the job summary - precisely
#   when a reviewer most needs to see which signal failed. Without `-e` every
#   signal is still collected, the complete table is emitted, and the script then
#   exits 1 EXPLICITLY at the end (see the final decision block). The non-zero
#   exit is guaranteed rather than suppressed; the only thing `-e` would change is
#   how much evidence the reviewer gets.

set -uo pipefail  # no -e BY DESIGN: collect the whole report, then fail explicitly.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

SUMMARY_FILE="${GITHUB_STEP_SUMMARY:-}"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

mago_field() {
  # $1 = file holding Mago JSON output (possibly followed by trailing text).
  # Prints "<issue-count>|<comma-separated-codes>" or "N" when no codes exist,
  # or "-" whenever the count cannot be established. "-" is the fail-closed
  # marker: it is treated as unmeasurable, never as zero.
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
  # same fail-closed contract: "-" means unmeasurable, never zero
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

echo "mago-quality-gate: running from ${REPO_ROOT}"

# --- signals ---------------------------------------------------------------
mago_available="false"
mago_analyze_rc="-"
mago_lint_rc="-"
mago_format_rc="-"

if [ -x vendor/bin/mago ]; then
  mago_available="true"

  vendor/bin/mago analyze --reporting-format json > "$tmpdir/mago-analyze.json" 2>&1
  mago_analyze_rc=$?

  vendor/bin/mago lint --reporting-format json > "$tmpdir/mago-lint.json" 2>&1
  mago_lint_rc=$?

  vendor/bin/mago format --check > "$tmpdir/mago-format.txt" 2>&1
  mago_format_rc=$?
else
  echo "mago-quality-gate: vendor/bin/mago is NOT executable - gate cannot be evaluated"
  echo "Mago not installed; the gate cannot be evaluated." > "$tmpdir/mago-analyze.json"
  : > "$tmpdir/mago-lint.json"
  : > "$tmpdir/mago-format.txt"
fi

mago_analyze="$(mago_field "$tmpdir/mago-analyze.json")"
mago_lint="$(mago_field "$tmpdir/mago-lint.json")"
mago_format_bytes="$(wc -c < "$tmpdir/mago-format.txt" | tr -d ' ')"

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

mago_analyze_count="${mago_analyze%%|*}"
mago_lint_count="${mago_lint%%|*}"

# --- raw output into the visible job log -----------------------------------
# The signals are captured to files so they can be PARSED reliably; they are
# echoed to stderr afterwards so a human reading the job log can check the parse
# rather than trust it. Stdout is reserved for the summary so the two never mix.
{
  echo "=== RAW mago analyze (--reporting-format json) ==="
  cat "$tmpdir/mago-analyze.json" 2>/dev/null || true
  echo "=== RAW mago lint (--reporting-format json) ==="
  cat "$tmpdir/mago-lint.json" 2>/dev/null || true
  echo "=== RAW mago format --check ==="
  cat "$tmpdir/mago-format.txt" 2>/dev/null || true
  echo "=== RAW phpstan (--error-format json) ==="
  cat "$tmpdir/phpstan.json" 2>/dev/null || true
} >&2

# --- cross-tool classification (no majority vote, informational) -----------
if [ "$mago_analyze_count" = "-" ] || [ "$phpstan_count" = "-" ]; then
  verdict="UNKNOWN"
  verdict_note="one or both signals could not be measured; no conflict can be claimed from missing data"
elif [ "$mago_analyze_count" = "0" ] && [ "$phpstan_count" = "0" ]; then
  verdict="AGREE_CLEAN"
  verdict_note="both report a clean tree"
elif [ "$mago_analyze_count" != "0" ] && [ "$phpstan_count" != "0" ]; then
  verdict="AGREE_ISSUES"
  verdict_note="both report issues; the Mago gate fails on the Mago count below"
else
  verdict="CONFLICTED"
  verdict_note="the two analyzers DISAGREE - needs investigation, never a majority vote"
fi

# --- gate decision (FAIL-CLOSED) -------------------------------------------
# Every branch below is written so that the UNMEASURABLE case fails. Do not
# "simplify" these into a single happy-path check: an absent tool or unparsable
# output must never be able to produce a green gate.
gate_failures=()

if [ "$mago_available" != "true" ]; then
  gate_failures+=("vendor/bin/mago is absent or not executable - the gate cannot be evaluated")
fi

if [ "$mago_analyze_count" = "-" ]; then
  gate_failures+=("mago analyze produced no parsable result - the gate cannot be evaluated")
elif [ "$mago_analyze_count" != "0" ]; then
  gate_failures+=("mago analyze reported ${mago_analyze_count} issue(s)")
fi

if [ "$mago_lint_count" = "-" ]; then
  gate_failures+=("mago lint produced no parsable result - the gate cannot be evaluated")
elif [ "$mago_lint_count" != "0" ]; then
  gate_failures+=("mago lint reported ${mago_lint_count} issue(s)")
fi

if [ "$mago_format_rc" = "-" ]; then
  gate_failures+=("mago format --check could not be run - the gate cannot be evaluated")
elif [ "$mago_format_rc" != "0" ]; then
  gate_failures+=("mago format --check exited ${mago_format_rc} - the tree is not formatted")
fi

gate_status="PASS"
if [ "${#gate_failures[@]}" -gt 0 ]; then
  gate_status="FAIL"
fi

# --- report ----------------------------------------------------------------
emit "### Mago quality gate (blocking)"
emit ""
emit "This job **can fail the build**: a failure here blocks the merge."
emit "Mago is an additional signal only; PHPStan remains the static-analysis authority."
emit ""
emit "| Signal | Result | Exit |"
emit "| --- | --- | --- |"
emit "| mago analyze | ${mago_analyze} | ${mago_analyze_rc} |"
emit "| mago lint | ${mago_lint} | ${mago_lint_rc} |"
emit "| mago format --check | ${mago_format_bytes} bytes, see log | ${mago_format_rc} |"
emit "| phpstan (authority, informational here) | ${phpstan_count} error(s) | ${phpstan_rc} |"
emit ""
emit "**Cross-tool verdict: \`${verdict}\`** - ${verdict_note}"
emit ""
emit "**Gate decision: \`${gate_status}\`**"
emit ""

if [ "$verdict" = "CONFLICTED" ]; then
  emit "Mago and PHPStan disagree on this revision. Per tooling policy this is recorded as"
  emit "CONFLICTED rather than resolved by counting votes: a Mago-only finding may be a real"
  emit "defect PHPStan cannot see, or a Mago false positive. Investigate before acting."
  emit ""
fi

if [ "$mago_format_rc" = "8" ]; then
  emit "NOTE: Mago's formatter wants changes, but \`PHP-CS-Fixer\` decides formatting in this"
  emit "repository. Do not run \`mago format\` to make this number go away - fix the file, or"
  emit "raise the conflict with the formatting authority."
  emit ""
fi

if [ "$gate_status" = "FAIL" ]; then
  emit "The gate did not establish a clean tree. Blocking reasons:"
  emit ""
  for failure in "${gate_failures[@]}"; do
    emit "- ${failure}"
  done
  emit ""
  emit "Raw output for every signal is echoed to this job's log between the RAW markers."
  emit "This gate exits 1 deliberately: the non-zero exit is what makes it binding, and"
  emit "every unmeasurable case is on the failing side of the decision."
  exit 1
fi

emit "Every Mago signal was measured and clean. Raw output for every signal is echoed to"
emit "this job's log between the RAW markers; the parse above can be checked against it."
exit 0
