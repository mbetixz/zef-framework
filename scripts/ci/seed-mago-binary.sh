#!/usr/bin/env bash
#
# seed-mago-binary.sh - provision the Mago native binary as a VERIFIED artefact
# instead of letting the Composer wrapper fetch it at runtime.
# ---------------------------------------------------------------------------
# WHY THIS EXISTS (F-1)
#
# `carthage-software/mago` ships a PHP "wrapper" that, on first execution of
# `vendor/bin/mago`, downloads a native binary from the GitHub release of the
# version recorded in `vendor/composer/installed.php` and executes it. The
# wrapper performs NO integrity verification of any kind: the lockfile records
# `"shasum": ""` for the package, and the provisioning source has zero matches
# for checksum/sha256/hash_file/attestation/signature/gpg/integrity. A version
# pin therefore constrains *which* package is installed, but not the integrity
# of the artifact that is fetched afterwards.
#
# This script closes that gap for CI by pre-placing a binary whose bytes match a
# digest pinned in the workflow. It works because the wrapper short-circuits on
# `file_exists()` before it ever builds a download URL:
#
#   vendor/carthage-software/mago/composer/src/internal.php
#     $storageDir     = "mago-{$version}-{$triple}";                    // :545
#     $releaseDir     = "{$binDir}/{$version}";                          // :546
#     $executablePath = "{$releaseDir}/{$storageDir}/mago{$ext}";        // :547
#     if (file_exists($executablePath)) { return $executablePath; }      // :549-551
#
# If the file is already there with the right bytes, the download path is never
# entered. Step 8 below asserts exactly that, so the guarantee is observed in CI
# rather than assumed.
#
# WHAT THIS SCRIPT DOES *NOT* DO
#
# It does not "fix" the upstream package, and it does not make `composer install`
# safe in general: the wrapper remains unverified code, and a developer machine
# still downloads an unverified binary. It protects the CI path only. See the
# accompanying audit report for the residual risk and the follow-up option that
# removes the wrapper entirely.
#
# DESIGN RULES (fail-closed)
#
#   * Every failure exits non-zero. There is no "skip and carry on".
#   * The wrapper's directory layout is asserted, not assumed: if upstream
#     changes it, this script fails loudly instead of seeding into a path the
#     wrapper would ignore (which would silently restore the unverified download).
#   * The version actually installed is asserted to equal the pinned version, AND
#     the constraint DECLARED in composer.json is asserted to be that same exact
#     version (step 2b). The first closes "the lockfile resolved elsewhere"; the
#     second closes "composer.json silently relaxed/raised the constraint". A
#     caret range can no longer survive either direction.
#   * A pre-existing target with the WRONG bytes is a hard failure, never a
#     silent overwrite: something put bytes there that this pipeline did not
#     verify.
#
# Usage (all inputs come from the workflow's `env:` block):
#   MAGO_VERSION=1.49.0 \
#   MAGO_TRIPLE=x86_64-unknown-linux-gnu \
#   MAGO_TARBALL_SHA256=<sha256> \
#   MAGO_BINARY_SHA256=<sha256> \
#   bash scripts/ci/seed-mago-binary.sh
#
# NOTE: this script deliberately does NOT use `continue-on-error` at the caller
# and must never be given it. A seed failure that is absorbed by an advisory job
# would leave Mago free to download an unverified binary - the exact failure this
# script exists to prevent.

set -euo pipefail

# --- 0. configuration ------------------------------------------------------
# No defaults for the digests: an unset pin must abort, not fall back.
: "${MAGO_VERSION:?MAGO_VERSION must be pinned by the workflow}"
: "${MAGO_TRIPLE:?MAGO_TRIPLE must be pinned by the workflow}"
: "${MAGO_TARBALL_SHA256:?MAGO_TARBALL_SHA256 must be pinned by the workflow}"
: "${MAGO_BINARY_SHA256:?MAGO_BINARY_SHA256 must be pinned by the workflow}"

MAGO_RELEASE_BASE_URL="${MAGO_RELEASE_BASE_URL:-https://github.com/carthage-software/mago/releases/download}"
BIN_DIR="${MAGO_BIN_DIR:-vendor/carthage-software/mago/composer/bin}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$REPO_ROOT"

workdir="$(mktemp -d)"
trap 'rm -rf "$workdir"' EXIT

fail() {
  printf '\nseed-mago-binary: FAIL: %s\n' "$*" >&2
  printf 'seed-mago-binary: refusing to continue - Mago must not fall back to an unverified download.\n' >&2
  exit 1
}

say() { printf 'seed-mago-binary: %s\n' "$*"; }

# --- 1. assert the contract this script depends on -------------------------
# If the wrapper is not present the repository is not in the expected state and
# every assumption below is void.
LAUNCHER="${BIN_DIR}/mago"
[ -f "$LAUNCHER" ] || fail "wrapper launcher not found at ${LAUNCHER}; was 'composer install' run?"

# The seed is only effective because the launcher routes through ensure_binary()
# and that function short-circuits on file_exists(). Assert the call site still
# exists rather than trusting a report.
grep -q 'ensure_binary' "$LAUNCHER" \
  || fail "${LAUNCHER} no longer calls ensure_binary(); the pre-seed contract is void, re-audit before re-enabling"

WRAPPER_SRC="vendor/carthage-software/mago/composer/src/internal.php"
[ -f "$WRAPPER_SRC" ] || fail "wrapper source not found at ${WRAPPER_SRC}"

# --- 2. assert the INSTALLED version equals the PINNED version -------------
# This is the assertion that closes the caret-range hole: composer.json allows
# "^1.49", so a lockfile refresh can resolve to a version this script was not
# reviewed against. Reading it through the same accessor the wrapper uses
# (InstalledVersions::getPrettyVersion()) checks the very value the download URL
# would be built from.
#
# shellcheck disable=SC2016  # single quotes are intentional: the PHP source must
# reach PHP unexpanded by the shell (no $ interpolation by bash).
installed_version="$(php -r '
require "vendor/autoload.php";
$v = \Composer\InstalledVersions::getPrettyVersion("carthage-software/mago");
echo $v === null ? "" : $v;
')" || fail "could not read the installed Mago version via InstalledVersions"

[ -n "$installed_version" ] || fail "Mago is not installed; was 'composer install' run?"

if [ "$installed_version" != "$MAGO_VERSION" ]; then
  fail "installed Mago is ${installed_version} but this pipeline pins MAGO_VERSION=${MAGO_VERSION}.
       The pin and the lockfile have drifted apart. Re-pin deliberately:
         - verify the new release's tarball and binary digests from two sources,
         - update MAGO_VERSION / MAGO_TARBALL_SHA256 / MAGO_BINARY_SHA256 together,
         - or pin the constraint exactly so this cannot happen silently."
fi

# --- 2b. assert the DECLARED constraint is the SAME exact pin --------------
# The assertion above compares the INSTALLED version against the pin, so it
# catches a lockfile that resolved somewhere else. It does NOT catch the reverse
# drift: composer.json quietly relaxing "1.49.0" back to "^1.49" (or raising the
# constraint) while MAGO_VERSION stays put. That reverse drift is exactly how the
# original caret range let the wrapper's download target move without anyone
# editing this workflow, so it is asserted directly rather than inferred from the
# install. Both directions are now closed.
#
# shellcheck disable=SC2016  # single quotes intentional: PHP source, not shell.
declared_constraint="$(php -r '
$json = json_decode(file_get_contents("composer.json"), true);
$c = $json["require-dev"]["carthage-software/mago"] ?? null;
echo is_string($c) ? $c : "";
')" || fail "could not read the declared mago constraint from composer.json"

[ -n "$declared_constraint" ] \
  || fail "composer.json no longer declares carthage-software/mago in require-dev"

if [ "$declared_constraint" != "$MAGO_VERSION" ]; then
  fail "composer.json declares '${declared_constraint}' for carthage-software/mago but this pipeline pins MAGO_VERSION=${MAGO_VERSION}.
       The declared constraint and the provisioning pin have drifted apart.
       Both are real changes and must move together:
         - pin composer.json to the exact version (no ^, ~, >= or * range), and
         - re-verify MAGO_TARBALL_SHA256 / MAGO_BINARY_SHA256 from two sources."
fi

# The string comparison above is only trustworthy for an EXACT version, so the
# shape is required explicitly rather than by enumerating range syntax. This also
# closes the case where MAGO_VERSION itself was set to a range, which the plain
# string equality above would happly accept when both sides carried it.
if [[ ! "$declared_constraint" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  fail "composer.json pins carthage-software/mago as '${declared_constraint}', which is not an exact x.y.z version.
       A range is what allowed a lockfile refresh to move the wrapper's download
       target unnoticed. Pin the exact version instead."
fi

say "declared constraint matches the provisioning pin: ${declared_constraint}"

# --- 3. derive the target path from the wrapper's own layout -----------------
STORAGE_DIR="mago-${MAGO_VERSION}-${MAGO_TRIPLE}"
RELEASE_DIR="${BIN_DIR}/${MAGO_VERSION}"
TARGET="${RELEASE_DIR}/${STORAGE_DIR}/mago"

say "version=${MAGO_VERSION} triple=${MAGO_TRIPLE}"
say "target=${TARGET}"

# --- 4. already seeded? ----------------------------------------------------
if [ -e "$TARGET" ]; then
  if printf '%s  %s\n' "$MAGO_BINARY_SHA256" "$TARGET" | sha256sum --check --strict --quiet; then
    say "target already present with the pinned digest; nothing to do"
    exit 0
  fi
  fail "${TARGET} exists but does NOT match the pinned digest.
       Bytes this pipeline did not verify are already in the path the wrapper
       trusts. Remove that file (or the vendor tree) and re-run; do not adopt it."
fi

# --- 5. fetch the tarball ---------------------------------------------------
ARCHIVE="${workdir}/${STORAGE_DIR}.tar.gz"
URL="${MAGO_RELEASE_BASE_URL}/${MAGO_VERSION}/${STORAGE_DIR}.tar.gz"
say "fetching ${URL}"
curl -sSL --fail --retry 3 --retry-delay 2 -o "$ARCHIVE" "$URL" \
  || fail "could not download ${URL}"

# --- 6. verify the tarball BEFORE anything is unpacked --------------------
# Same idiom already used by this repository in .github/workflows/php-sast.yml
# (curl --fail, then sha256sum --check --strict). --strict makes sha256sum exit
# non-zero on a malformed line as well as on a mismatch.
printf '%s  %s\n' "$MAGO_TARBALL_SHA256" "$ARCHIVE" | sha256sum --check --strict --quiet \
  || fail "tarball digest mismatch. Expected ${MAGO_TARBALL_SHA256}."
say "tarball verified (sha256 matches the pin)"

# --- 7. extract and verify the inner binary -------------------------------
mkdir -p "${workdir}/extract"
tar -xzf "$ARCHIVE" -C "${workdir}/extract" || fail "could not extract the archive"

EXTRACTED="${workdir}/extract/${STORAGE_DIR}/mago"
[ -f "$EXTRACTED" ] || fail "archive layout unexpected: ${STORAGE_DIR}/mago not found inside the tarball.
       Upstream changed the archive; re-audit the path derivation in step 3."

printf '%s  %s\n' "$MAGO_BINARY_SHA256" "$EXTRACTED" | sha256sum --check --strict --quiet \
  || fail "binary digest mismatch inside the verified archive. Expected ${MAGO_BINARY_SHA256}."
say "binary verified (sha256 matches the pin)"

# --- 8. place it where the wrapper's short-circuit will find it ------------
mkdir -p "$(dirname "$TARGET")"
install -m 0755 "$EXTRACTED" "$TARGET" || fail "could not install the binary to ${TARGET}"

# Re-read the installed file: this catches a truncated or partial copy, which
# step 7 alone cannot see.
printf '%s  %s\n' "$MAGO_BINARY_SHA256" "$TARGET" | sha256sum --check --strict --quiet \
  || fail "the file installed at ${TARGET} does not match the pin"

# --- 9. prove the download path is NOT taken ------------------------------
# This is the invariant that makes the whole approach meaningful. The wrapper is
# invoked here and its output inspected: if it reports a download, the seed was
# not honoured (wrong path / layout change) and an unverified binary is in play.
probe_log="${workdir}/wrapper-probe.log"
if ! "$LAUNCHER" --version >"$probe_log" 2>&1; then
  fail "the seeded binary could not be executed through the wrapper; see ${probe_log}"
fi

if grep -qE 'Downloading mago|Download failed|Failed to download' "$probe_log"; then
  fail "the wrapper attempted a download even though ${TARGET} exists.
       The seed was NOT honoured - an unverified binary would be used. Output:
$(sed 's/^/         /' "$probe_log")"
fi

say "wrapper resolved the seeded binary without any download: $(tr -d '\r' < "$probe_log" | head -1)"
say "OK: Mago ${MAGO_VERSION} (${MAGO_TRIPLE}) is pre-seeded and digest-verified."
