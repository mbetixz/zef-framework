# Governance: merge gates, ratchets and credential policy

This document records the **enforced** repository governance as measured from the
GitHub API and the workflow files — not as intended. Where an enforcement rule and
its configuration disagree, the disagreement is written down here rather than
smoothed over.

Measured against the protection API on `main` @ `87e2ba94e21b4f22b911ab5e359dad4f03c39416`
(re-verified 2026-09-24).

## 1. Branch protection on `main`

| Setting | Value | Consequence |
|---|---|---|
| Required status contexts | **7**: `PHP lint, audit, static analysis and style` · `PHP SAST (Semgrep)` · `CodeQL` · `dependency-review` · `gitleaks` · `PHPBench` · `Build API documentation` | All seven must report green before merge. Corrected from the previous six: `Analyze (actions)` never published on a pull-request head and was replaced by `CodeQL` (the context that does publish), and `PHP SAST (Semgrep)` — the gate that analyses the PHP production source and previously could fail *without* blocking a merge — was added. |
| `strict` | `true` | The branch must be **up to date with `main`** — a PR that falls behind must update its branch. A `behind` PR is therefore expected behaviour, not a broken PR |
| `enforce_admins` | `true` | No bypass, including for the owner |
| `dismiss_stale_reviews` | `true` | Pushing after review re-requires review |
| `require_code_owner_reviews` | `true` | Requires a review from a matching `.github/CODEOWNERS` owner |
| `required_approving_review_count` | **`0`** | ⚠️ See N1 below |
| `required_linear_history` | `false` | Merge commits are permitted |
| `allow_force_pushes` / `allow_deletions` | `false` | History on `main` is append-only through the API |

### N1 — contradictory code-owner rule (open finding)

`require_code_owner_reviews: true` together with `required_approving_review_count: 0`
is a contradictory configuration: GitHub only applies the code-owner requirement when
at least one approving review is required, so today the rule cannot be satisfied *or*
blocked. Until this run the repository also had **no `.github/CODEOWNERS` file at all**,
so the setting referenced an artifact that did not exist.

`.github/CODEOWNERS` now exists. Raising `required_approving_review_count` to `>= 1`
is an **owner decision**: it changes the merge flow for every pull request.

### N2 — the PHP SAST gate is now a required context (closed 2026-09-24)

As measured, neither CodeQL nor the PHP SAST job was among the required contexts, so a
security regression detected by either did not block a merge. That is resolved for the
gate that actually analyses the **PHP production source**: `PHP SAST (Semgrep)` is now a
required context, and that job decides its outcome on the exit code of the blocking
`ERROR`-severity scan over `src/**` — never on code-scanning availability, which is
`continue-on-error` by design.

CodeQL is still **not** a content gate for PHP here, because CodeQL does not support PHP
at all; requiring it adds no PHP coverage. It is required as the context that proves the
`actions`-language analysis ran (see §1), which is why `Analyze (actions)` was replaced by
`CodeQL` rather than simply deleted.

## 2. Quality ratchets

A ratchet converts "this should be better" into "this must not get worse". Each one below
is enforced, cheap, and cannot be relaxed without a visible edit.

### 2.1 Mutation score — per zone

- **Enforced aggregate gate:** `composer mutation:ci` → `--min-msi=85 --min-covered-msi=90`
  over every first-party source directory (~9.4k mutants). It runs in
  `mutation.yml` (release tags and `workflow_dispatch`), **not** on the push/PR
  path — see 2.5 — and `release.yml` waits for it before publishing.
- **Enforced per-zone ratchet:** `composer mutation:zones` →
  `scripts/ci/assert-zone-coverage.php --floor=95`, run in `ci.yml` as the
  `Zone mutation ratchet` step.
- The ratchet reads committed evidence: `docs/mutation/baseline.tsv` (frozen floor per
  zone) against `docs/mutation/zones.tsv` (current measurement), for exactly the zones listed
  in `scripts/f16_zones.tsv`. It re-runs **no** mutation suite, so the pipeline cost does not
  double. It fails if a zone is missing from either table, if a row claims `OK` below the
  floor, if a `DEBT`/`UNKNOWN` row carries no reason, if `evidence` is empty, or if **any zone
  has regressed below its frozen baseline** — that last check is the point of the gate.
- **The baseline only ratchets up.** Raising it is a deliberate, reviewable edit; lowering a
  measured score below it fails the build. Closing a zone is: write tests, re-measure, promote
  the row to `OK`.
- Format, campaign tooling and pitfalls: `docs/mutation/README.md`.

### 2.2 PHPStan baseline

`phpstan-baseline.neon` currently suppresses **510** findings. PHPStan runs at level max and
passes *because* those 510 are held in the baseline.

Rule: **the baseline may not grow without an owner decision.** A pull request that adds
entries must state, in its description, which findings it adds and why they cannot be fixed
in the same change. Any reduction is welcome and requires no approval. Removing the baseline
outright is a multi-release campaign, not a single step, and would block the pipeline until
finished.

### 2.3 Every workflow job declares a timeout

Every job in `.github/workflows/` declares `timeout-minutes`. This is a ratchet on
*pipeline liveness*: without it, a stuck step holds a required status check open
indefinitely and — because a hung run's `updated_at` freezes at job start — a hang is
indistinguishable from a slow run from the outside. With it, the job fails closed and
releases the check. New jobs must declare one.

### 2.4 The release gate waits for a terminal CI state

`release.yml` must not read a CI `conclusion` before the run is `completed`. Doing so
converts a legitimate race into a spurious failure: pushing a tag while `ci.yml` for the
same SHA is still `in_progress` yields an empty conclusion, which a naive gate reads as
"failed". The gate polls `status` to a terminal value, bounded by a deadline so a genuinely
stuck pipeline still fails closed. See `references/cicd-gitlab-github.md` in the ZEF skill
package for the full rule.

### 2.5 The mutation suite runs at release, the ratchet runs on every push

The aggregate Infection gate was relocated out of the push/PR path into
`mutation.yml`, triggered by `v*.*.*` tags and `workflow_dispatch`. It was
**relocated, not weakened**:

- **Same threshold and scope.** `composer mutation:ci` → `--min-msi=85 /
  --min-covered-msi=90` over the same first-party directories. No number moved.
- **Same blocking power.** `release.yml` includes `mutation.yml` in the list of
  workflows it waits for (see 2.4), so a release fails unless the suite passes.
  A gate that is not waited on is not a gate — the workflow alone would prove
  nothing.
- **Same evidence.** `build/infection.log` and `build/infection-summary.log` are
  uploaded as the `mutation-evidence` artifact even when the gate fails, so a red
  verdict is diagnosable without re-running the suite.
- **The cheap ratchet stays.** `composer mutation:zones` still runs in `ci.yml` on
  every push and PR. It re-runs no suite — it reads `docs/mutation/` — so the
  zone-regression signal still lands minutes after the push, while the
  hour-long suite is what moved.

Why: the mutation score is a property of the **committed source tree**, not of the
change under review, so on the PR path it could not change any merge decision it
was not already making — while consuming ~55 of `ci.yml`'s ~66 minutes on every
push and holding the required check `PHP lint, audit, static analysis and style`
open for roughly an hour per merge. The trade is explicit and reversible: one
workflow file and one line in `release.yml`.

## 3. Credential policy

### 3.1 Declared by name only

The automation runs with a single GitHub credential, injected at runtime and referred to by
name (`GITHUB_TOKEN`). Its **value** must never appear in any repository artifact — not in
source, workflows, `.git/config`, documentation, logs, reports, PR descriptions, or CI logs.

Presence is checked with a masked probe (`scripts/check_env_vars.py`), which prints only
`PRESENT` / `ABSENT` and never a value.

### 3.2 Never persist a runtime credential into repository state

A token reaches shell steps but **not** the agent's own tool-call environment. Writing it
into a git remote URL persists plaintext in `.git/config`. Push with an inline credential URL
or a credential helper that reads the environment **at run time**:

```bash
git config --local credential.helper '!f() { echo username=x-access-token; echo password=$GITHUB_TOKEN; }; f'
```

The stored value is the *expression*, evaluated per invocation, so `.git/config` holds no
secret. After any credential touch, verify: `git config --local --list` shows no value, and a
workspace-wide scan finds no occurrence.

### 3.3 Least-privilege scope

The token in use carries a wide OAuth scope set (including `admin:org`,
`admin:repo_hook`, `admin:ssh_signing_key`, `delete:packages`, `workflow`, `repo`).
The automation needs **`repo`** and, only if it must write workflow files, **`workflow`**.
Narrowing the scope is an **account-level owner action** — it cannot be performed from
inside the repository.

## 4. Supply chain

- Actions are pinned to a commit SHA with the human-readable version in a trailing comment;
  Dependabot (`.github/dependabot.yml`) rewrites the SHA and keeps the comment in sync.
- Dependabot covers `composer` and `github-actions`, weekly, grouped, capped at 5 open PRs per
  ecosystem. Runtime dependencies are deliberately **not** grouped: a production dependency
  bump deserves its own review and its own `dependency-review` run.
- SBOM and provenance generation run in `sbom.yml` and `release.yml`.

## 5. Bot-managed release state

The draft release `v2.17.1` is **managed by Release Drafter** (`release-drafter.yml`): its body
is regenerated from merged pull requests, and it is published through `release.yml` when the
owner pushes the tag. A draft whose body already lists merged PRs is the mechanism working as
designed. Do not delete release drafts on the assumption that they are stale.
