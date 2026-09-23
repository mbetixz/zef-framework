## What changed

<!-- One or two sentences. Name the component and the behaviour that differs. -->

## Why

<!-- The problem this solves, or the debt item it closes. Link the issue/PR if one exists. -->

## Evidence

<!-- VERIFIED evidence only. Paste the command, the run id, the commit sha, or the log excerpt.
     "It should work" is not evidence. -->

- Command / gate run:
- Revision (sha):
- Result:

## Quality gates

- [ ] `composer stan` (PHPStan level max, within the frozen baseline)
- [ ] `composer mutation:ci` (or a scoped run — state the scope and the reason)
- [ ] `composer mutation:zones` (per-zone ratchet, when `docs/mutation/` changes)
- [ ] `composer lint` / `bin/zef --self-test`
- [ ] Tests added or updated for the changed behaviour

## Risk & rollback

<!-- Blast radius, and how to revert. Delete this section only if the change is docs-only. -->

- Risk:
- Rollback:

## Checklist

- [ ] No secret, token, or credential value appears in the diff, logs, or comments
- [ ] Commit messages follow `type(scope): subject`
- [ ] Documentation updated when public behaviour or a gate changed
