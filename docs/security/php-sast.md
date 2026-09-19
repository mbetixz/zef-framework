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
  receives the SARIF this workflow uploads, under its own category
  (`php-sast-source` / `php-sast-tests`), and those results must not be read as
  CodeQL output.
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
| `src/**/*.php` (production / runtime source) | yes | **Blocking** on `ERROR` severity |
| `tests/**/*.php`, `tools/**/*.php`, `scripts/**/*.php` | yes | **Non-blocking** — reported through code scanning only |

**Why test and tooling code is scanned rather than excluded.** The alternative
("it is not production, so skip it") is not accepted here: test and CI helper code
runs with repository credentials and on developer machines, so a dangerous pattern
there has real impact, and excluding whole trees is exactly how a scanner becomes
theatre. It is therefore analysed — but its findings are **reported, not blocking**,
because those trees have not been triaged yet and a first-run red gate teaches
reviewers to bypass the gate.

**Why `src/` blocks on first run.** The baseline scan of `src/**` on `main` produced
**zero** findings (section 6), so making it blocking introduces no false-positive
friction today and guarantees the gate cannot silently degrade.

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
| `WARNING` (anywhere) | **Non-blocking.** Surfaced in the job log and in code scanning. Warning-level rules include the taint rules whose confidence metadata is `MEDIUM CONFIDENCE`; they are deliberately not blocking while their false-positive rate on this codebase is unknown. |
| `INFO` | Not reported (`--severity WARNING` floors the reporting scan). |

**Policy shape.** The gate fails on high-severity findings only, and never because
a scan *ran*. Crucially, the gate is **not** made green by ignoring findings:
there is no `#nosemgrep`, no rule disabled, no blanket path exclusion for the
scanned trees, and no `|| true` on the scan step.

**Recommendation on promoting `WARNING` to blocking.** Do this only after the
warning population has been triaged on a real body of `src/` code. On the current
baseline there is nothing to triage, so promoting now would be a decision made
without evidence. Recommended trigger: once `src/` has enough code that the
warning list is stable across a few weeks of pull requests, review the list, fix
or narrowly suppress each item, then flip the warning scan to blocking and delete
this paragraph.

---

## 6. Baseline findings

Scan of `main` (`151f961c…`) with the pinned ruleset, at the pinned engine version:

| Severity | Count | Classification |
|---|---|---|
| Critical | 0 | — |
| High | 0 | — |
| Medium | 0 | — |
| Low / Info | 0 | — |
| **Total** | **0** | No findings to triage |

Files analysed at baseline: `src/**` (1 file), `scripts/**` (1 file), `tests/**`
(1 file). Rules loaded: 36 PHP security rules (`php/lang/security` plus the
`audit/` and `injection/` subtrees).

Because the baseline is empty, **no baseline file and no suppression of any kind
was created for this change.** Nothing is being hidden to make the gate green.

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

There is currently **nothing suppressed**. If a finding must be suppressed later,
the rules are:

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
| Artefacts | Two SARIF files in the runner temp directory, published to code scanning | The workspace is not archived and uploaded, and no credential is written to an artefact. |
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
