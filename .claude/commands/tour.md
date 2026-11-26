---
description: Guided tour of the Echo project for a reviewer (architecture, live proof, tests)
---
Give the reviewer a 5-minute guided tour of this repository. Use the `echo-qa` MCP tools; if they are not available, run the equivalent `docker compose` commands from CLAUDE.md.

1. `project_overview` — summarise in ≤10 lines what the system does and how it is built.
2. `stack_status` with env `dev` — if anything is down, tell the reviewer to run `docker compose up -d` and stop.
3. `watch_admin_socket` with `probe: true` — show the measured POST→admin WebSocket latency as live proof of the real-time requirement.
4. Show the walkthrough table: for steps S1…S9 call `explain_step` (or read docs/TRACEABILITY.md) and present one line per step: what happens → the key test that locks it.
5. Point at the URLs: wall http://localhost:20080, admin http://localhost:21080 (admin / admin12345), demo http://localhost:20080/demo.

Be concrete, cite file paths, and report only what the tools actually returned.
