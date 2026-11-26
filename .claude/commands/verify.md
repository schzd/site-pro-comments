---
description: Run the full verification gate and report honestly
---
Run the release gate and report exactly what happened:

1. `qa` command `gaps` — any gap is a finding; list it.
2. `run_tests` suite `all` (unit → smoke → regression → e2e; needs `docker compose --profile test up -d`).
3. `qa` command `matrix` with args `["--strict"]` — every step must be guarded by every required suite and green.
4. `qa` command `history` — mention slow or flaky tests.

Report pass/fail per suite with test counts copied from the output. Never claim a suite passed that you did not run.
