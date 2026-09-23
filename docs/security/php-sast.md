# PHP SAST baseline

Security-oriented source-code analysis for the PHP code in this repository, run by
`.github/workflows/php-sast.yml`.

This document records **what is scanned, by what, with which pinned inputs, what
blocks a pull request, and what this gate explicitly is not.** It is the reference
for the scope and severity decisions, so those decisions are reviewable instead of
implicit in a workflow file.

---

## 1. What this gate is, and what it is not

| Gate | Question it answers |
|---|---|
| **PHP SAST (this document)** | Does the PHP source contain a security-relevant code pattern (injection, unsafe execution, unsafe deserialisation, SSRF, weak crypto, …)? |
| **CodeQL** (`.github/workflows`, default setup, `actions` language) | Are the **GitHub Actions workflows** themselves insecure or misconfigured? |
| **PHPStan** (`phpstan.neon.dist`, level max) | Is the PHP code **type-correct** and internally consistent? |
| **Deptrac** (`deptrac.yaml`) | Are the **architectural layer boundaries** respected? |
| **Gitleaks** (`secret-scan.yml`) | Is a **credential** committed in any file type? |
| **Dependency Review** (`dependency-review.yml`) | Does this pull request **introduce a vulnerable dependency**? |
| **Composer security audit** (`ci.yml`, `composer run audit`) | Does the **installed dependency set** carry a known advisory or an unaccepted abandoned package? |
| **Anchore SBOM** (`sbom.yml`) | What is in the **dependency inventory**? |
| **Infection** (Deep QA) | Are the **tests** strong enough to catch a mutation? |

This gate **does not replace, duplicate or weaken any of the above.** Concretely:

- It is **not** a CodeQL PHP analysis — no such thing exists here. Code scanning
  receives the SARIF this workflow uploads, under its own categories
  (`php-sast-source` — blocking production, `php-sast-source-warning` — report-only
  production, `php-sast-tests` — report-only tooling). Those results must not be
  read as CodeQL output.
- It is **not** a type checker. PHPStan already covers correctness; Semgrep only
  looks for security-relevant patterns, and a Semgrep-clean tree says nothing
  about type correctness.
- It is **not** a secret scanner. Gitleaks already runs on every push and pull
  request.
- No existing step in `ci.yml`, `secret-scan.yml`, `sbom.yml`, `dependency-review.yml`,
  `phpbench.yml`, `pages.yml`, `release.yml`, `release-drafter.yml`, `auto-fix.yml`
  or `composer-lock.yml` was modified, removed or relaxed by this change.

---

## 2. Tool selection

### Chosen

**Semgrep Community Edition (OSS CLI), pinned to the `semgrep/semgrep` container
image at `1.177.0`** — executed with `semgrep scan` inside the pinned image, with
the **ruleset itself pinned to an immutable `semgrep/semgrep-rules` commit**.

### Alternatives considered

| Candidate | Verdict | Reasoning |
|---|---|---|
| **Semgrep CE CLI** (chosen) | **Adopted** | Purpose-built security rule engine with an extensive, curated, **PHP-specific** security ruleset (36 PHP security rules in the pinned tree), taint-style dataflow rules for injection classes, SARIF 2.1.0 output, real severity levels (`ERROR`/`WARNING`/`INFO`) with a `--severity` filter, an exit-code contract usable as a gate, and **no privileged credential required** to run. Runs entirely offline once the ruleset is fetched. |
| **`semgrep/semgrep-action`** (the official GitHub Action) | **Rejected** | The repository is **archived** (`archived: true`, last push 2024-04-09). Depending on an archived action for a security gate is a supply-chain liability. The CLI route adds no credential and is fully pinnable, so nothing is lost. |
| **PHP-native static security analysis** (e.g. taint analysis via the existing static-analysis ecosystem) | **Not adopted now** | It would add a second full static-analysis engine for overlapping PHP coverage, needs a project bootstrap for the ZEF source tree that does not exist yet, and the baseline is currently small enough that a dedicated engine cannot be justified. Revisit if first-party PHP source grows substantially and Semgrep's dataflow coverage proves insufficient. |
| **Custom Semgrep rules** | **Deferred, supported** | Worth writing for ZEF-specific security boundaries (module registration, security policy bootstrap). Deferred because at baseline there is not yet a second-party codebase to model, and hand-written rules carry their own false-negative risk. The pinned ruleset is a plain directory, so repository-local rules can be added later through the same `--config` mechanism. |
| **Adding Syft / a second SBOM tool** | **Rejected** | Anchore already produces the SBOM. Adding a tool to obtain a *primitive* that is orthogonal to source analysis would be duplication. |

### Licensing

The ruleset comes from `semgrep/semgrep-rules`, licensed under the **Semgrep Rules
License v1.0**: a non-exclusive, royalty-free, worldwide licence to use the rules
for **your own internal business purposes**; it does **not** permit distributing
the rules or making them available to others as a service. Running them inside
this repository's own CI is an internal use and is within the licence. The rules
are **not** vendored into the repository and are **not** redistributed — the
workflow downloads them from upstream at a pinned commit at run time. If this
repository ever exposes rules as a product or service, this decision must be
revisited.

---

## 3. Pinned analysis inputs

Every input is pinned and independently verifiable. **No value here comes from
memory**; each was resolved and cross-checked against its upstream source.

| Input | Pinned value | How it was verified |
|---|---|---|
| Semgrep engine | `semgrep/semgrep` image at `1.177.0`, digest `sha256:acaac22f…d81198` | Resolved through the Docker registry v2 manifest for the tag (anonymous pull token), read from the `Docker-Content-Digest` response header. `sha256:acaac22f…d81198` is the tag's digest, confirmed through two independent lookups. |
| Ruleset commit | `semgrep/semgrep-rules@40b8c63f75dc7c22c8a77482d73bfb864b146f7e` | Default branch (`develop`) head resolved through the GitHub API. |
| Ruleset tarball | `sha256:b7e483ab…ec4919` | The codeload tarball for that exact commit was downloaded **twice**; both downloads produced the identical digest, so the artefact is reproducible. The workflow re-checks this digest and **fails closed** if it does not match. |
| `actions/checkout` | `d23441a4…af803` (`v6.1.0`) | Annotated tag peeled through the GitHub API and cross-checked against the repository tag list. Same pin as the repository's existing workflows. |
| `github/codeql-action/upload-sarif` | `1c5b6756…e09fd` (`v4.38.1`) | Annotated tag peeled through the GitHub API, then the peeled commit cross-matched to its tag name in the tag list. |

All `uses:` in this workflow resolve to 40-character commit SHAs. No tag, branch
or `latest` reference is used as a security boundary.

---

## 4. Scope

| Path set | Analysed | Effect |
|---|---|---|
| `src/**/*.php` (production / runtime source), `ERROR` severity | yes | **Blocking** — the job fails |
| `src/**/*.php` (production / runtime source), `WARNING` severity | yes | **Non-blocking** — reported through code scanning only |
| `tests/**/*.php`, `tools/**/*.php`, `scripts/**/*.php` | yes | **Non-blocking** — reported through code scanning only |

**Why test and tooling code is scanned rather than excluded.** The alternative
("it is not production, so skip it") is not accepted here: test and CI helper code
runs with repository credentials and on developer machines, so a dangerous pattern
there has real impact, and excluding whole trees is exactly how a scanner becomes
theatre. It is therefore analysed — but its findings are **reported, not blocking**,
because those trees have not been triaged yet and a first-run red gate teaches
reviewers to bypass the gate.

**Why `src/` blocks.** The baseline `ERROR`-severity scan of `src/**` on `main`
produced **zero** findings (section 6), so the blocking threshold introduces no
false-positive friction today and guarantees the gate cannot silently degrade. That
zero is an `ERROR`-severity statement only: the same tree yields 3 findings under the
`WARNING` floor, which is why those are reported rather than blocking.

**Excluded paths** are listed explicitly in the committed `.semgrepignore` (`.git/`,
`vendor/`, `build/`, `dist/`, `node_modules/`, tool caches). Note the interaction
this file has with the tool: committing it **replaces** Semgrep's built-in default
ignore list, so `tests/` — which the default list would silently skip — is only
analysed because the file lists exclusions explicitly and does not list `tests/`.
That behaviour was verified empirically, not assumed.

---

## 5. Severity and blocking policy

| Severity | Policy |
|---|---|
| `ERROR` in `src/**` | **Blocks the pull request.** Semgrep is invoked with `--severity ERROR --error`; any `ERROR` finding makes the job fail. Error-level PHP rules in the pinned ruleset cover the injection classes where a false negative is most damaging (code injection via dynamic evaluation, command execution, SSRF). |
| `WARNING`, in `src/**` | **Non-blocking, but always evaluated.** A dedicated report-only pass (*Scan production PHP source at WARNING severity*) scans production source at `--severity WARNING`, so warning-level rules — including the taint rules whose confidence metadata is `MEDIUM CONFIDENCE` — are looked for in production code and reported, without being able to block. Before that pass existed, `src/**` was evaluated at `ERROR` only, so a whole severity band was invisible in production *by construction*: a zero count under `ERROR` never meant "no warning-level match". |
| `WARNING`, in `tests/**`, `scripts/**`, `tools/**` | **Non-blocking.** Surfaced in the job log and in code scanning. |
| `INFO` | Not reported (`--severity WARNING` floors the reporting scan). |

**Policy shape.** The gate fails on high-severity findings only, and never because
a scan *ran*. Crucially, the gate is **not** made green by ignoring findings: no
rule is disabled, there is no blanket path exclusion, and there is no `|| true` on
the blocking scan step. There **are** two in-source `#nosemgrep` suppressions,
both on `eval()` in production code; they are accepted, justified and registered
in section 7. This document previously stated that none existed — that was wrong,
and is corrected there.

**Two-pass policy (measured 2026-09-24).** `WARNING` is evaluated in a separate
report-only pass rather than being promoted to blocking, and rather than being
left unlooked for. Choosing between keeping two passes and promoting the warning
pass to blocking is an **owner decision**:

- *Two passes (current).* Production warning findings are visible and published to
  code scanning, and can never fail the job. Cost: one extra Semgrep invocation
  over `src/**`.
- *Promote to blocking (not done).* Add `--error` to the warning pass. Do this only
  after the warning population has been triaged: at the time of writing that pass
  immediately reports 3 production findings (section 6), and an untriaged red gate
  is how reviewers learn to bypass a gate.

Promotion is a one-line change and must stay a deliberate, reviewable edit rather
than a by-product of this coverage fix.

---

## 6. Baseline findings

Re-measured on `main` @ `87e2ba94e21b4f22b911ab5e359dad4f03c39416` (2026-09-24) with
the pinned ruleset and engine, by reproducing this workflow's exact invocation
locally rather than by copying a previous figure. The numbers published in this
section earlier (a single file per tree, 0 findings everywhere) described an early
three-file baseline and were stale by roughly two orders of magnitude in file
count; they are corrected here.

Pinned ruleset inventory: **36** rule files under `php/lang/security` (23
top-level + 10 in `injection/` + 3 in `audit/`). Rules actually evaluated: **20**
under the `ERROR` floor, **16** under the `WARNING` floor.

| Pass | Targets | Findings | Blocking |
|---|---|---|---|
| `src/**`, `ERROR` floor | 358 files | **0** | yes (`--error`) |
| `src/**`, `WARNING` floor (new pass) | 358 files | **3** | no (report-only) |
| `tests/**` + `scripts/**`, `WARNING` floor | 97 files | 30 | no (report-only) |

The 3 production warning findings are all `php.lang.security.unlink-use`, and all
three sit on best-effort cleanup of a temporary file that this code named itself
immediately before attempting an atomic `rename()`:
`src/Adapters/Http/UploadedFile.php:130`, `src/Adapters/Router/RouteCache.php:44`,
`src/Application/Container/Autowiring/AutowireAotCompiler.php:100`. They are **not**
suppressed and not fixed by this change — they are now *visible* for the first
time, which is the point of the new pass. Triaging them is the next step.

No baseline file and no rule-level suppression was created for this change.
Nothing is hidden to make the gate green, and — the other direction — nothing that
was visible before became invisible: the blocking `ERROR` pass is the same
invocation it was before this change.

**Detection was proven, not assumed.** Composing rules that find nothing proves
nothing, so detection was validated against a **synthetic, out-of-repository
fixture** containing six deliberately unsafe constructs (through the shell and
dynamic-evaluation constructs, cookie-driven deserialisation, request-controlled
file access, request-controlled redirect). The pinned ruleset reported **7 findings
across 5 distinct rules** and the exit code moved from `0` to `1` with `--error`.
No insecure code was ever placed in `src/` — the fixture lived outside the
repository and was never committed.

**Exit-code contract (measured, not assumed).** `0` = clean; `1` = findings present
**and** `--error` supplied; `2` = internal error; `7` = configuration could not be
loaded (e.g. a pinned ruleset failed to fetch). The last one matters: a broken
pin turns the job red instead of producing a silent zero-finding pass.

**Runtime.** ~2 s locally for the three baseline files; ~8 s for a full multi-config
invocation including registry fetch. On a runner, the dominant cost is fetching the
pinned ruleset and the engine image.

---

## 7. Suppression policy

**Registered suppressions: 2** — both `php.lang.security.eval-use`, both in
production source, both accepted by owner decision on 2026-09-24. This section
previously stated that nothing was suppressed; that was wrong, and the register
below is the correction. Under rule 4, the accepted list is reviewable here as a
whole rather than scattered across source files.

| # | Rule | Location | Reason | Lifetime |
|---|---|---|---|---|
| 1 | `php.lang.security.eval-use` | `src/Adapters/Runtime/TinkerSession.php:68-70` | The `bin/zef tinker` REPL evaluates developer-typed code **by design**. Its caller (`bin/zef`, `zef_tinker()`) refuses to start when `ZEF_ENV=production` unless `--force` is passed, so the input is neither remote nor untrusted; the class itself performs no I/O. Removing `eval()` would remove the feature. | **Permanent** — the feature *is* `eval` |
| 2 | `php.lang.security.eval-use` | `src/Application/Container/Autowiring/AutowireAotCompiler.php:163` | `evalFactory()` evaluates **code this compiler generated itself** one step earlier (`generateFactory()` returns a `static fn` expression assembled from `var_export`'d scalars, a `\`-prefixed class name, and integer dependency placeholders — no caller-supplied payload reaches the string). It is the compile-time pattern Symfony's DI container dump uses, and the resulting closure is what makes reflection-free cold start possible. The suppression is **load-bearing**: the CI invocation over `src/**` exits `1` ("2 findings (2 blocking)") without it, measured on the same ruleset. | **Permanent** — the pattern is the feature |

Both suppressions are `inSource` and scoped to a single line. Neither hides a
rule-class-wide exclusion, and neither removes an evaluation that would otherwise
be counted: the findings stay published to code scanning as alerts 1 and 2
carrying `"kind": "inSource"`, so a reviewer sees the suppression instead of it
being silent.

If a finding must be suppressed in future, the rules are:

1. Never suppress at rule level, and never exclude a whole scanned tree to silence
   findings — that converts a gate into a formality.
2. Suppress at the narrowest possible **line or block**, inline, with the reason in
   the suppression comment.
3. Every suppression states: the rule, the location, the reason, and whether it is
   temporary (with a review date) or permanent.
4. Suppressions are recorded in this document, so the accepted list is reviewable
   as a whole rather than scattered across source files.
5. A suppression that is not justified in writing is not accepted.

---

## 8. Trigger policy

| Event | Reason |
|---|---|
| `pull_request` (targeting `main`) | Findings must be caught **before** merge. Pull requests from forks are analysed normally: the workflow uses no repository secret and no write-capable token in the analysis path, and it does not use `pull_request_target`. |
| `push` (to `main`) | Catches findings that entered through a direct push or a bypassed check, and keeps the `main` branch's code-scanning state current. |
| `workflow_dispatch` | Manual re-run, used when triaging or after changing the pinned ruleset. |

`pull_request_target` is deliberately **not** used. It would run with a
write-capable token in a context a pull-request author can influence; there is no
security justification for that here.

---

## 9. Permissions, credentials and code scanning

| Item | Value | Justification |
|---|---|---|
| Workflow-level `permissions` | `contents: read` | Baseline for the whole workflow. The analysis only reads the checked-out tree. |
| Job `permissions` | `contents: read`, `security-events: write` | `security-events: write` is required **only** by `upload-sarif`, to publish SARIF into code scanning. The job cannot write to the repository. |
| Repository secret | **None** | Semgrep CE needs no token to run against local source, and this workflow does not log in to the Semgrep platform, so no `SEMGREP_APP_TOKEN` is configured or required. |
| Network access | Outbound to `codeload.github.com` (pinned ruleset) and the container registry (pinned engine image) | No source code is uploaded to any Semgrep service. Telemetry is disabled (`--metrics=off`) and version checks are disabled (`--disable-version-check`). |
| Artefacts | Three SARIF files in the runner temp directory (production-`ERROR`, production-`WARNING`, tooling), published to code scanning under three categories | The workspace is not archived and uploaded, and no credential is written to an artefact. |
| Fork behaviour | Analysis runs; the upload is the only privileged step, and a fork pull request receives a read-only token, so `security-events: write` is not granted | Upload is `continue-on-error` so a token-scope limitation cannot turn an otherwise clean analysis red. |

**Known weakness (accepted, owner decision).** The SARIF **upload** steps are
marked `continue-on-error: true`. The analysis itself still fails the job on a
blocking finding — but a failure *of the upload* (for example if code scanning is
unavailable, or on a fork pull request where `security-events: write` is not
granted) will not turn the run red. The trade-off is deliberate: it keeps the
security decision on the PHP analysis, where the merge-blocking evidence lives,
instead of on the availability of the code-scanning backend. The consequence is
that SARIF publication is best-effort — **the job log and exit code, not code
scanning, are the source of truth.** If the owner would rather have publication
itself be a hard gate, that is a one-line change (drop `continue-on-error`), and
it should be paired with confirming that code scanning accepts third-party SARIF
on this repository.

---

## 10. Local reproduction

```bash
# engine: any Docker host; ruleset: the pinned commit
RULES_SHA=40b8c63f75dc7c22c8a77482d73bfb864b146f7e
curl -sSL --fail -o rules.tar.gz \
  "https://codeload.github.com/semgrep/semgrep-rules/tar.gz/$RULES_SHA"
echo "b7e483abf001c405a3e908251ff66cb198a26702aff5fe4c5f0c4b2fffec4919  rules.tar.gz" \
  | sha256sum --check --strict
mkdir -p rules && tar -xzf rules.tar.gz -C rules --strip-components=1

# same invocation the workflow uses
docker run --rm -v "$PWD:/src:ro" -v "$PWD/rules:/rules:ro" \
  semgrep/semgrep@sha256:acaac22ffc7b7cc5926de0751b223bce0b2491c33d18422fa72f632c78d81198 \
  semgrep scan --config=/rules/php/lang/security \
    --metrics=off --disable-version-check --severity ERROR --error /src/src
```

A Docker-less local equivalent uses the same version from PyPI
(`pip install semgrep==1.177.0`) with `--config <extracted>/php/lang/security`.

---

## 11. Maintenance

- **Engine and ruleset upgrades are deliberate pull requests**, never automatic.
  An upgrade updates the pinned digest/commit in the workflow, re-runs the local
  reproduction, and records the new baseline in section 6 of this document.
- **Never replace the pin with a tag or `latest`.** `latest` as a security
  boundary means the analysis changes without review.
- **Revisit this decision** if first-party PHP source grows enough that a
  ZEF-specific ruleset becomes worthwhile (section 2), or if the Semgrep Rules
  Licence stops covering this repository's use.
