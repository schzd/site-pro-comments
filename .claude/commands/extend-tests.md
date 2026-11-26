---
description: The app changed — find what is unguarded and grow the test suites to cover it
argument-hint: "[what changed, optional]"
---
The application changed ($ARGUMENTS). Scale the test suites to match, using the `test-scaler` sub-agent workflow:

1. `qa` `gaps` and `qa` `plan` (args `["--base=origin/main"]` if that ref exists) — list new routes, unclaimed files, steps missing a required suite.
2. Update `qa/steps.php`: claim every new route / file by an existing step or add a new step (title, routes, paths, required suites).
3. For each missing (step, suite) pair: `qa` `scaffold` (`--step --suite --name`, `--app` for regression), then implement the test following the conventions of the existing tests in that suite (see CLAUDE.md → Tests).
4. `run_tests` for the affected suites with `-g step-<ID>`; iterate until green. If a test exposes a real bug, fix the app and say so.
5. `qa` `matrix --strict` and `qa` `gaps` must both be clean. Commit tests + `qa/steps.php` + `docs/TRACEABILITY.md` + `qa/history.json` together.
