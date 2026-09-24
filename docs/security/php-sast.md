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

- It is **not** a CodeQL PHP analysis — no such thing exists here, because CodeQL
  supports no PHP language at all. Code scanning receives the SARIF this workflow
  uploads, under its own categories (`php-sast-source` — production at the `ERROR`
  floor, blocking; `php-sast-source-warning` — production at the `WARNING` floor,
  **blocking since 2026-09-24**; `php-sast-tests` — test and tooling code, **also
  blocking since 2026-09-24**). Those results must not be read as CodeQL output.
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
| `src/**/*.php` (production / runtime source), `WARNING` severity | yes | **Blocking** — the job fails (promoted 2026-09-24) |
| `tests/**/*.php`, `tools/**/*.php`, `scripts/**/*.php`, `WARNING` severity | yes | **Blocking** — the job fails (promoted 2026-09-24) |

**Why test and tooling code is scanned *and* blocks.** The alternative ("it is not
production, so skip it") is not accepted here: test and CI helper code runs with
repository credentials and on developer machines, so a dangerous pattern there has
real impact, and excluding whole trees is exactly how a scanner becomes theatre.
Leaving the tree analysed but silenced fails the other way too — it produces
alerts nobody is obliged to act on.

That tree was report-only at first for a *sequencing* reason, not a policy one: it
carried 30 un-triaged findings, and a first-run red gate teaches reviewers to
bypass the gate. The pass shipped report-only, its population was triaged and
registered (§7.2), and only then was `--error` added — the same sequence used for
the production `WARNING` floor in §5. Neither tree was made green by *ignoring* a
finding: both were made green by *dispositioning* it in writing.

**Why `src/` blocks.** The baseline `ERROR`-severity scan of `src/**` on `main`
produced **zero** findings (section 6), so the blocking threshold introduces no
false-positive friction today and guarantees the gate cannot silently degrade. The
`WARNING` floor over the same tree produced three findings, and those were triaged
and registered before the `WARNING` floor was itself made blocking — a blocking floor
is only defensible once its population is dispositioned, otherwise the gate colours
red for reasons nobody has decided about.

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
| `WARNING`, in `src/**` | **Blocks the pull request** (promoted 2026-09-24). A dedicated pass (*Scan production PHP source at WARNING severity*) scans production source at `--severity WARNING --error`, so warning-level rules — including the taint rules whose confidence metadata is `MEDIUM CONFIDENCE` — are looked for in production code *and* can fail the job. Before that pass existed, `src/**` was evaluated at `ERROR` only, so a whole severity band was invisible in production *by construction*: a zero count under `ERROR` never meant "no warning-level match". The pass shipped report-only for one change (PR #41) so its findings could be triaged first; that triage is in §7, and promotion followed it. |
| `WARNING`, in `tests/**`, `scripts/**`, `tools/**` | **Blocks the pull request** (promoted 2026-09-24). A dedicated pass (*Scan tests and tooling PHP*) scans these trees at `--severity WARNING --error`. All 30 findings it produced were triaged and registered in §7.2 **before** the promotion; §4 explains why the ordering matters. |
| `INFO` | Not reported (`--severity WARNING` floors the reporting scan). |

**Policy shape.** The gate fails on high-severity findings only, and never because
a scan *ran*. Crucially, the gate is **not** made green by ignoring findings: no
rule is disabled, there is no blanket path exclusion, and there is no `|| true` on
the blocking scan step. There **are** thirty-five in-source `#nosemgrep`
suppressions — two on `eval()`, thirty-three on `unlink()`; they are accepted,
justified and registered in sections 7.1 and 7.2. This document previously stated that none
existed — that was wrong, and is corrected there.

**Three-pass policy — every floor is blocking as of 2026-09-24 (owner decision).**
The `WARNING` floor over `src/**` is evaluated in its own pass and supplies
`--error`, exactly as the `ERROR` floor does; the `WARNING` floor over `tests/`,
`scripts/` and `tools/` supplies `--error` as well. **There is no report-only
surface left.** What differs between the three passes is only *what is scanned*,
never *whether a finding blocks*.

The promotion was **sequenced, not bundled**. The pass was introduced report-only
(PR #41) so that its findings would be *visible* without turning an untriaged
population into a red gate, and promotion followed only once the three findings it
revealed had been dispositioned in writing (§7). An untriaged red gate is how
reviewers learn to bypass a gate, so the ordering matters more than the one-line
change.

The change is deliberately visible in the diff and carries its own rationale. The
`ERROR` step is byte-identical to before, no rule was disabled, no path was
excluded, and no finding was left unexamined: detection width increased *and*
blocking width increased; nothing was relaxed.

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
| `src/**`, `ERROR` floor | 360 files | **0** | yes (`--error`) |
| `src/**`, `WARNING` floor | 360 files | **3** | yes (`--error`, promoted 2026-09-24) |
| `tests/**` + `scripts/**`, `WARNING` floor | 97 files | 30 | yes (`--error`, promoted 2026-09-24) |
| `tests/**` + `scripts/**`, `WARNING` floor **after** the §7.2 triage | 97 files | 30 registered (exit `0`) | yes (`--error`) |

Re-measured again on `main` @ `2ed883e` after the promotion: the same three
`WARNING` findings, all `php.lang.security.unlink-use`, on best-effort cleanup of a
temporary file this code named itself on the failure path of an atomic `rename()`:
`src/Adapters/Http/UploadedFile.php:136`, `src/Adapters/Router/RouteCache.php:48`,
`src/Application/Container/Autowiring/AutowireAotCompiler.php:104`. (The line
numbers move from the PR #41 figures because the register comments added in §7 sit
above each call.)

Those three were **triaged before the pass was promoted**, not after: they are
accepted suppressions, registered and justified in §7, with the argument for
suppression-over-rewrite measured in §7.1. The `ERROR` floor remains 0 findings.

`tests/**`, `scripts/**` and `tools/**` were **still report-only at this
measurement**. They were promoted to blocking later the same day, after their own
population had been dispositioned in writing; that re-measurement and its register
are in §7.2.

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

**Registered suppressions: 35** — two `php.lang.security.eval-use` and thirty-three
`php.lang.security.unlink-use`, all accepted by owner decision on 2026-09-24. Three
of the `unlink-use` entries are in production source (§7.1); thirty are in test
code (§7.2). This section previously stated that nothing was suppressed; that was
wrong, and the register below is the correction. Under rule 4, the accepted list is
reviewable here as a whole rather than scattered across source files.

The three `unlink-use` entries were added when the `WARNING` pass over production
source was promoted to blocking, and the order matters: the pass first reported
these three (`report-only`, PR #41), they were then triaged, and only then did the
pass become merge-blocking. The suppression is what made a *green* blocking gate
possible without ignoring a finding.

| # | Rule | Location | Reason | Lifetime |
|---|---|---|---|---|
| 1 | `php.lang.security.eval-use` | `src/Adapters/Runtime/TinkerSession.php:68-70` | The `bin/zef tinker` REPL evaluates developer-typed code **by design**. Its caller (`bin/zef`, `zef_tinker()`) refuses to start when `ZEF_ENV=production` unless `--force` is passed, so the input is neither remote nor untrusted; the class itself performs no I/O. Removing `eval()` would remove the feature. | **Permanent** — the feature *is* `eval` |
| 2 | `php.lang.security.eval-use` | `src/Application/Container/Autowiring/AutowireAotCompiler.php:163` | `evalFactory()` evaluates **code this compiler generated itself** one step earlier (`generateFactory()` returns a `static fn` expression assembled from `var_export`'d scalars, a `\`-prefixed class name, and integer dependency placeholders — no caller-supplied payload reaches the string). It is the compile-time pattern Symfony's DI container dump uses, and the resulting closure is what makes reflection-free cold start possible. The suppression is **load-bearing**: the CI invocation over `src/**` exits `1` ("2 findings (2 blocking)") without it, measured on the same ruleset. | **Permanent** — the pattern is the feature |
| 3 | `php.lang.security.unlink-use` | `src/Adapters/Http/UploadedFile.php:136` | Best-effort cleanup of `$targetPath . '.zef-tmp-' . bin2hex(random_bytes(8))` — a name this method generated itself at line 66. No request input reaches the argument, and the call runs only on the failure path (`if (!$success)`), after the atomic `rename()` to the caller's destination has already failed; it can therefore only remove a partially written temp file of this method's own making. | **Permanent** — no rewrite can satisfy the rule (§7.1) |
| 4 | `php.lang.security.unlink-use` | `src/Adapters/Router/RouteCache.php:48` | Same shape: cleanup of `$path . '.' . bin2hex(random_bytes(6)) . '.tmp'` (line 39), a self-named temp sibling of the cache file, on the branch where `rename($tmp, $path)` has already returned false. Not reachable with request-controlled input. | **Permanent** — no rewrite can satisfy the rule (§7.1) |
| 5 | `php.lang.security.unlink-use` | `src/Application/Container/Autowiring/AutowireAotCompiler.php:104` | Same shape: cleanup of `$path . '.tmp.' . getmypid()` (line 95), a self-named temp sibling of the AOT export, on the branch where `rename($tmp, $path)` has already returned false. Reached from the compile-time CLI path, never from a request. | **Permanent** — no rewrite can satisfy the rule (§7.1) |

All thirty-five suppressions are `inSource` and scoped to a single line. None hides
a rule-class-wide exclusion, and none removes an evaluation that would otherwise be
counted: every suppressed finding stays published to code scanning as an alert
carrying `"kind": "inSource"`, so a reviewer sees the suppression instead of it
being silent.

That claim is **measured, not asserted**. Running the promoted invocation against
the tree with the suppressions in place exits `0` while the emitted SARIF still
contains all 30 results, each carrying `"suppressions": [{"kind": "inSource"}]` -
the marker neutralises the *exit code*, not the *finding*. Strip the markers on a
copy and the same invocation exits `1` with the same 30 results. So a suppression
buys a green gate here only by leaving the alert visibly open in code scanning. The three `unlink-use` entries are **not** a change of behaviour —
they document calls that already existed and were already flagged; what changed is
that they are now recorded and reviewable in one place.

### 7.1 Why the three `unlink-use` findings are suppressed rather than fixed

Suppression was chosen over a rewrite on measurement, not preference. The rule is
`pattern: unlink(...)` minus `pattern-not: unlink("...",...)` — it matches **every**
`unlink()` whose argument is not a bare string literal, irrespective of provenance.
Removing the `@` or the enclosing `if` changes nothing.

Measured against the pinned ruleset, on a synthetic probe **outside the repository**
(no insecure code was ever placed in `src/`):

| Call form | Matched by `unlink-use`? |
|---|---|
| `unlink('/tmp/known-file')` — literal | no |
| `unlink($tmp)` | **yes** |
| `unlink(realpath($tmp))` — hardened | **yes** |
| `unlink(dirname($tmp) . '/' . basename($tmp))` — hardened | **yes** |
| `unlink((string) $tmp)` | **yes** |
| `unlink(sprintf('%s', $tmp))` | **yes** |

So a "fix" that adds path hardening would still be flagged, and the only forms that
clear the rule are worse: inline a literal path (wrong — the name is computed per
call), or express the removal as something other than `unlink()`. The calls are also
not reachable with attacker-controlled input, and the rule's own metadata rates them
`confidence: LOW` / `likelihood: LOW`.

The honest characterisation is therefore: **a false positive on a low-risk cleanup
path, accepted in writing, with the alert left visible in code scanning.** Nothing
was hidden to obtain a green gate, and the rule stays enabled for every other
`unlink()` in the tree.

### 7.2 The thirty `unlink-use` findings in test code

Added when the `tests/`, `scripts/` and `tools/` pass was promoted to blocking.
Same problem class as §7.1, same conclusion, but **dispositioned individually
rather than in bulk** — "30 findings, all in tests, suppress them all" would be a
blanket decision dressed up as a triage. Each call site was read, its argument's
provenance was traced, and the whole tree was searched for request-derived input.

The measurement that matters: **0 of the 34 `unlink(` call sites in `tests/` can
have a request superglobal in scope.** Every argument traces to `tempnam()`,
`sys_get_temp_dir()`, a `bin2hex(random_bytes())`-suffixed name the test itself
computed, or an entry returned by `scandir()`/`glob()` over a directory the test
created. The rule — `pattern: unlink(...)` minus `pattern-not: unlink("...",...)` —
matches on *argument shape*, never on provenance, so it cannot distinguish these
from a genuinely request-driven delete; §7.1's measurement of that limitation
applies unchanged.

| # | File | Call sites | What it removes |
|---|---|---|---|
| 6 | `tests/Unit/EdgeMatrixF10ContainerTest.php` | 57, 79 | temp AOT file from `sys_get_temp_dir() . '/zef-aot-' . bin2hex(...)` |
| 7 | `tests/Unit/EdgeMatrixF6MixedTest.php` | 360 | `tempnam()` used as an `error_log` sink, after `file_get_contents()` |
| 8 | `tests/Unit/EdgeMatrixF7AotRadixTest.php` | 98, 120, 151, 175, 190, 200 | temp AOT fixtures in `finally` teardown |
| 9 | `tests/Unit/EdgeMatrixHttpTest.php` | 368, 390, 988 | `tempnam()` passed to `Stream`/`StreamFactory`, closed first |
| 10 | `tests/Unit/EdgeMatrixMakerGeneratorsTest.php` | 447 | a dirent from `scandir()` over the test's own temp dir |
| 11 | `tests/Unit/EdgeMatrixMakerTest.php` | 597 | same shape, in `rmRecursive()` teardown |
| 12 | `tests/Unit/EdgeMatrixRouterKernelTest.php` | 335, 353, 379 | `tempnam()` file converted to a dir; `glob()` over the test's own dir |
| 13 | `tests/Unit/EdgeMatrixRuntimeTest.php` | 341, 367, 668 | `tempnam()` `error_log` sink, after restoring `error_log` |
| 14 | `tests/Unit/HttpDeepTest.php` | 240, 251 | `tempnam()` target and its `-moved` sibling |
| 15 | `tests/Unit/HttpTest.php` | 66, 67 | `tempnam()` target and the `(string)`-cast temp path |
| 16 | `tests/Unit/KernelEdgeTest.php` | 454 | `tempnam()` `error_log` sink |
| 17 | `tests/Unit/ModulesPluginsTest.php` | 347 | `tempnam()` `error_log` sink |
| 18 | `tests/Unit/RuntimeEdgeTest.php` | 248 | `tempnam()` `error_log` sink |
| 19 | `tests/V2100EnterpriseSuite.php` | 450 | temp route-cache path from the suite's own `tempnam()` |
| 20 | `tests/V290AutowireSuite.php` | 300, 301 | `tempnam()` malformed-AOT fixture and the AOT export path |

**Lifetime: permanent** — §7.1 applies unchanged, no rewrite satisfies the rule.
Every entry is `inSource`, single-line, and carries its reason inline. The alerts
stay visible in code scanning.

#### Known false negative (recorded, not patched)

Four call sites in the same tree are **not** reported by the rule and therefore
carry **no** suppression marker:
`tests/Unit/EdgeMatrixF10KernelTest.php:432`,
`tests/Unit/EdgeMatrixF8ObsInfraTest.php:562`,
`tests/Unit/MutationDeepHttpTest.php:77`,
`tests/Unit/ObservabilityTest.php:662` — all written as the fully-qualified
`@\unlink($sink)`, which the rule's `pattern: unlink(...)` does not match.

This is recorded rather than patched for two reasons. First, a suppression marker
on a call the rule does not flag would be decoration, not evidence. Second, it is a
**ruleset false negative** — the more consequential direction for a security gate —
and it now belongs in the review checklist for the next ruleset pin move. The same
construct appears nowhere under `src/`, so production coverage is unaffected today.

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
- **When moving the pin, re-test the suppression markers.** A new ruleset version
  may rename a rule id (which silently un-suppresses every marker) or extend a rule
  to a construct it previously missed. §7.2's recorded false negative on the
  fully-qualified `\unlink()` form is a concrete instance of the latter: a pin move
  is the moment to check whether it has been fixed.
- **Never replace the pin with a tag or `latest`.** `latest` as a security
  boundary means the analysis changes without review.
- **Revisit this decision** if first-party PHP source grows enough that a
  ZEF-specific ruleset becomes worthwhile (section 2), or if the Semgrep Rules
  Licence stops covering this repository's use.
