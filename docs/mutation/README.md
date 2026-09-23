# Mutation-testing evidence & the per-zone ratchet

This directory holds the **committed per-zone mutation evidence** for ZEF Framework
and the machine-readable table that the `scripts/ci/assert-zone-coverage.php` gate
reads.

## Why this exists

The enforced mutation gate is **aggregate**:

```
composer mutation:ci   →   infection --threads=max --min-msi=85 --min-covered-msi=90
```

It runs Infection over every first-party source directory (~9.4k mutants) and fails
below **MSI 85 / Covered MSI 90**. That gate says nothing about any individual area:
a single zone can regress from 94% to 60% and the aggregate number will absorb it.

The owner's stated target is stricter and per-area: **MSI ≥ 95% for every
area/module**. Until this directory existed, no artifact carried that measurement,
so the target was unfalsifiable — neither provable nor disprovable from the
repository.

## What the gate actually does (and does not do)

`scripts/ci/assert-zone-coverage.php` is a **ratchet over committed evidence**, not a
measurement:

- It does **not** run Infection. Running the mutation suite a second time in CI
  would roughly double pipeline cost.
- It compares two committed tables and fails on drift:

  | File | Role |
  |---|---|
  | `scripts/f16_zones.tsv` | **canonical** zone registry |
  | `baseline.tsv` | the **frozen floor** per zone — the measured MSI at freeze time |
  | `zones.tsv` | the **current** measurement table |

- Failure conditions: a canonical zone missing from either table; a row claiming
  `OK` below the floor; a `DEBT`/`UNKNOWN` row with no reason; an empty `evidence`
  cell; **or any zone measured below its frozen baseline.**
- A mutation score therefore cannot silently regress. Raising a baseline is a
  visible, reviewable edit; fixing tests is what lowers the gap.

A green run proves **the ratchet holds**. It does **not** assert that every zone
meets the 95% target — at freeze time most do not, and pretending otherwise would be
a false claim rather than a gate. Closing a zone requires writing tests, re-measuring,
and promoting its row from `DEBT` to `OK`.

## `zones.tsv` / `baseline.tsv` format

Both tables share one shape: one row per canonical zone, pipe-separated, seven columns.

```
zone|msi|covered_msi|total|status|evidence|reason
```

| Column | Meaning |
|---|---|
| `zone` | Zone id — must exist in `scripts/f16_zones.tsv` |
| `msi` | Measured Mutation Score Indicator, percent (`-` when `UNKNOWN`) |
| `covered_msi` | Measured Covered MSI, percent (`-` when `UNKNOWN`) |
| `total` | Total mutants generated for the zone |
| `status` | `OK` (msi ≥ floor) · `DEBT` (msi < floor) · `UNKNOWN` (no measurement) |
| `evidence` | Where the number came from — a path a reviewer can open (e.g. `docs/mutation/evidence/infection-summary-<zone>.json`). Never empty |
| `reason` | Required for `DEBT` and `UNKNOWN`; may be empty for `OK` |

Rules enforced by the gate:

1. The zone set must match `scripts/f16_zones.tsv` **exactly** — no missing rows,
   no extra rows.
2. `status=OK` with `msi` below `--floor` (default 95) → **FAIL**.
3. `status=DEBT` with `msi` at or above the floor → **FAIL** (promote it to `OK`).
4. `status=DEBT` or `UNKNOWN` without a `reason` → **FAIL**.
5. Empty `evidence` → **FAIL**.
6. A zone measured **below its frozen baseline** in `baseline.tsv` → **FAIL**
   (restore the score, or raise the baseline deliberately).

## How to (re)generate the measurements

Per-zone measurement is a **campaign**, not a quick step. On a one-core CPU quota
(`/sys/fs/cgroup/cpu.max` = `100000 100000`) one remediation round re-runs the whole
zone — initial suite plus every mutant — so the cost is not proportional to the gap.

```bash
# 0. The suite needs a live Redis with the password the tests expect.
redis-server --port 6399 --daemonize yes --save '' --appendonly no \
  --requirepass "$ZEF_TEST_REDIS_PASSWORD"   # same value the suite expects

# 1. Measure every canonical zone (resumable; writes build/zone-campaign-results.json).
python3 scripts/mutation/zone_campaign.py "" 3600 0

# 2. Read the numbers.
python3 -c "import json;print(json.dumps(json.load(open('build/zone-campaign-results.json')),indent=1))"
```

Notes learned the hard way:

- **Read the CPU quota, not `nproc`.** `nproc` reports host cores (48 here) while the
  cgroup quota is one core. Choosing `--threads` from `nproc` oversubscribes and gains
  nothing.
- **Generate zone configs at the repository root.** Infection resolves a relative
  `source.directories` entry against the **config file's** directory, not the shell's
  working directory; a config written into `build/` fails with
  `directory build/src/... does not exist`.
- **Capture Infection's stdout.** A zone that produces an empty summary has failed
  *before* mutation generation — with `stdout` discarded, an aborted initial suite and
  a legitimately empty zone are indistinguishable.
- **A missing Redis makes every zone silently empty.** `RedisStoreTest` aborts the
  initial suite, Infection exits before generating mutants, and the per-zone summary is
  never written. `mutation.yml` starts Redis as an explicit step (so does `ci.yml`, for
  the PHPUnit suites); a local campaign must too.

## Relationship to the aggregate gate

Both gates run and both matter — but they run on **different triggers**:

| Gate | Scope | Threshold | Step |
|---|---|---|---|
| `composer mutation:ci` | all mutants, aggregate | MSI 85 / Covered 90 | `mutation.yml` → `Mutation testing (Infection)` — release tags and dispatch |
| `composer mutation:zones` | per canonical zone, ratcheted | floor 95, bounded open items | `ci.yml` → `Zone mutation ratchet` — every push and PR |

The aggregate suite is release-time only (see `docs/GOVERNANCE.md` 2.5). It was
relocated there, not weakened: the threshold and scope are unchanged, and
`release.yml` refuses to publish unless `mutation.yml` concluded `success`. The
cheap ratchet deliberately stayed on the push/PR path so a zone regression still
fails a pull request minutes after the push rather than at release time.

The per-zone ratchet is deliberately **cheap and fast**: it reads this directory and
fails on drift. Closing a zone from `DEBT` to `OK` is done by writing tests and
re-measuring, then updating the row — the gate will refuse a silent edit that lowers a
recorded `OK` below the floor, and will refuse a `DEBT`→`OK` promotion while the
measured number is still short.
