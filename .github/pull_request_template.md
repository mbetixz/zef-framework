<!--
Thanks for contributing. Keep this description factual: the CI gates are the
acceptance authority, and an empty body gives a reviewer nothing to check the
diff against (see PR #29, which arrived with an empty description).
-->

## What changed

<!-- One or two sentences. What behaviour is different after this merge? -->

## Why

<!-- The problem, the risk it created, or the requirement being met. -->

## How it was verified

<!-- Paste the actual commands and their results. "CI will tell us" is not a
     verification. Name the run id / job when the evidence is a pipeline. -->

- [ ] `vendor/bin/phpunit`
- [ ] `composer stan` (PHPStan level max, frozen baseline)
- [ ] `vendor/bin/deptrac analyse --config-file=deptrac.yaml`
- [ ] `vendor/bin/php-cs-fixer check --diff --using-cache=no`
- [ ] `vendor/bin/phpcs`
- [ ] `vendor/bin/rector process --dry-run`
- [ ] coverage gate (`composer coverage:gate`)

## Mutation-testing impact

<!-- Required when the change touches src/**. Name the zone(s) affected and
     what the measured MSI is. Do not claim a mutation score without the
     Infection summary behind it. -->

- Affected zone(s):
- MSI before / after:

## Risk and rollback

<!-- What is the blast radius if this is wrong, and how is it reverted? -->

- Risk:
- Rollback:

## Checklist

- [ ] No secret, token, or credential value appears anywhere in this diff, its
      description, or its CI logs.
- [ ] New gates/jobs declare `timeout-minutes` so a stuck job fails closed
      instead of holding the required check open.
- [ ] Any change to a quality gate states which gate it is (aggregate
      `--min-msi=85/90` in `composer mutation:ci`, versus a per-zone goal) and
      whether the number is measured or aspirational.
- [ ] Documentation under `docs/` is updated when public behaviour changes.
