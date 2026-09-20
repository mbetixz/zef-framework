# Mago — blocking quality gate

Status: **Production Gate (blocking)**. `Mago Quality Gate` is a required status
check on `main`; a red run blocks the merge.

## What the gate is, and what it is not

| Authority | Owns |
| --- | --- |
| PHP-CS-Fixer | formatting |
| PHPStan | static / type analysis |
| Deptrac | architecture |
| PHPUnit | runtime behaviour |
| Semgrep | security patterns |
| Gitleaks | secrets |
| **Mago** | **blocking static-quality gate** (this document) |

Mago is an *additional* signal, not a replacement for any of the above. When Mago
and PHPStan disagree the gate reports `CONFLICTED` and blocks; it never resolves a
disagreement by counting votes.

## Why the binary is pre-seeded

`carthage-software/mago` downloads a native binary at runtime on first use of
`vendor/bin/mago` and verifies **nothing**: the lockfile records `"shasum": ""`
and the provisioning source has no checksum, signature or attestation check. A
version pin therefore constrains *which* package is installed but not the
integrity of the artifact that is fetched afterwards.

`scripts/ci/seed-mago-binary.sh` closes that gap for CI: it downloads the release
tarball, verifies a pinned sha256, verifies the inner binary, and places the file
where the wrapper's `file_exists()` short-circuit finds it — so the unverified
download path is never entered. Step 9 of that script asserts this positively by
invoking the wrapper and failing if it reports a download.

## Two independent pin controls

Both are asserted by `scripts/ci/seed-mago-binary.sh`, in opposite directions:

1. **installed == pin** — the version Composer actually resolved must equal
   `MAGO_VERSION`. Catches a lockfile that resolved somewhere else.
2. **declared == pin** (step 2b) — the constraint *declared in `composer.json`*
   must be that same exact `x.y.z` version. Catches the reverse drift: somebody
   relaxing `1.49.0` back to `^1.49` while `MAGO_VERSION` stays put.

Direction 2 is the one that matters historically: the original caret range is what
let the wrapper's download target move without anyone editing the workflow.
A range can no longer survive either direction.

## Provisioning inputs

| Input | Value |
| --- | --- |
| `MAGO_VERSION` | `1.49.0` |
| `MAGO_TRIPLE` | `x86_64-unknown-linux-gnu` |
| tarball sha256 | `32248cbd418fa88522bcacdee8591f5a35cf25db11045f70cefe8b7cdde424ef` |
| binary sha256 | `b08fba538d0f2bca334d0423a68ac326f9888b616b96da7a5a0f8c2694cc02bc` |
| binary size | `30,359,864` bytes |

Both digests were corroborated from two independent sources: the release API's
per-asset `digest`, and a local re-hash of an actual download.

Bumping `MAGO_VERSION` requires re-pinning **both** digests in the same commit.

### Other triples (recorded, NOT activated)

These are recorded so a future musl/arm64 job does not have to re-derive them.
They are **not** wired into any workflow: no job currently provisions them, so
there is no lockstep bug today. A future job must add its own `MAGO_TRIPLE`,
its own digests, and its own seed step, in one commit.

| Triple | Tarball sha256 | Size |
| --- | --- | --- |
| `aarch64-unknown-linux-gnu` | `f6b70e00649c127af18911a26b7af0eeb98c8718d9235095b8e6e3391c8f6b64` | 9,962,662 |
| `aarch64-unknown-linux-musl` | `e23a75ab40d387dec50bbcdc0fde7b7555e018579788e1ebaecb5056ef5564a8` | 9,994,603 |

Each was corroborated the same way (release API `digest` + local re-hash).

## Runner notes

`ubuntu-24.04` is glibc x86_64, matching `MAGO_TRIPLE`. The wrapper derives the
triple from `detect_linux_libc()`, so a musl or arm64 runner needs its own triple
**and its own digests** — the x86_64 pins above do not cover it.

## Developer machines — deliberately NOT covered

CI does not reach a developer laptop. The local story is opt-in only:

```
git config core.hooksPath .githooks
```

`.githooks/pre-commit` runs PHP-CS-Fixer over *staged* PHP files (blocking, since
PHP-CS-Fixer is the formatting authority) and prints Mago's `--staged` output
**informationally**.

The hook deliberately does **not** block on Mago, and the reason changed when the
gate was promoted: it is not that "Mago is not a gate" — Mago *is* a required
check now. It is that a local hook cannot reproduce what the gate measures. CI
runs the **full tree** against a **digest-verified** binary provisioned by
`scripts/ci/`; a developer machine has neither. Blocking a commit on a weaker,
differently-sourced signal would be a **false gate** — it would look like the
gate while measuring something else. CI remains the only place the Mago verdict
is binding.

A developer machine still downloads an **unverified** binary when it first runs
Mago without pre-seeding. That residual risk is unchanged and remains open; the
follow-up that removes the wrapper entirely is tracked separately.

## When the gate is RED — operator procedure

A red run means one of two different things. Read the summary table the script
writes into the job summary before acting; never assume which one it is.

**A. The gate measured a Mago problem** (blocking reasons name a count `> 0`).
The gate is working as intended. Fix the code. Do **not** look for a bypass:
bypassing a true positive is how the gate stops meaning anything.

**B. The gate could not be evaluated** ("the gate cannot be evaluated" appears in
the blocking reasons, or the seed step failed). This is a provisioning or network
fault — a seed download failure, an unavailable release, a runner-image change,
or `vendor/bin/mago` absent. The change has **not** been shown to be bad, and it
has **not** been shown to be good: the gate is simply blind on that run.

### Admin bypass — case B only

For case B the documented recourse is the branch-protection admin bypass. All of
the following are mandatory:

1. **Only a repository admin may do it, and only for case B.**
2. Record **why** in a PR comment before merging, naming the failing step and the
   provisioning fault.
3. The bypass must be visible in the repository **audit log**. A silent or
   unattributed bypass is indistinguishable from a weakened gate.
4. **Re-run the gate on the next pull request.** A recurring case B is a bug to
   fix, not a standing exception.
5. Never "fix" case B by re-adding `continue-on-error`, by making the gate script
   exit 0 again, or by removing the context from the required list.

Bypassing **case A** is out of procedure and must not be done.

Note that admin bypass is not free here: `enforce_admins` is enabled on `main`,
so the classic admin-enforcement switch does not silently apply. A bypass is an
explicit, logged administrative action.

## Invariants — do not weaken

* The job-level `continue-on-error: true` is **absent** and must never come back.
  It rewrote a failing job's conclusion to success, which would silently
  neutralise the gate while every surface still claimed it was active.
* The seed step must never carry `continue-on-error`. A silent seed failure would
  leave Mago free to download an unverified binary — the exact condition the
  pre-seed exists to prevent.
* The `always()` diagnostic step must stay read-only and keep its explicit
  `exit 0`. It exists so the provisioning state is observed from inside a
  *failing* run; it must be able to neither mask nor manufacture a result.
* Do not remove `Mago Quality Gate` from the required status checks, and do not
  remove or weaken the other required contexts.
