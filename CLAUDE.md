# Echo — agent guide

Yii2 commenting system (Site.pro #php homework): visitors post on a public wall
without logging in; administrators see new comments arrive **live over
WebSockets** and can edit / hide / delete them. Everything runs in Docker.
There is no PHP on the host — run everything through `docker compose`.

## Fastest way in (for AI agents)

This repo ships an MCP server, **`echo-qa`** (see `.mcp.json`), that works on the
LIVE stack. Start the stack, then use its tools:

| Tool | Use it to |
|---|---|
| `project_overview` | this file + the pipeline registry |
| `stack_status` | HTTP / WebSocket / Redis-subscriber health (`env: dev` or `e2e`) |
| `watch_admin_socket` | **prove the real-time path**: posts a probe, measures POST→admin-frame latency |
| `post_comment` | create real traffic |
| `explain_step` | one pipeline step: code + the tests that lock it |
| `run_tests` | `unit` / `smoke` / `regression` / `e2e` / `all` (+ codecept args) |
| `qa` | `gaps`, `plan`, `scaffold`, `matrix`, `shards`, `history`, `steps` |

Slash commands: `/tour`, `/walkthrough`, `/verify`, `/extend-tests`.
Sub-agent for growing the suites: `.claude/agents/test-scaler.md`.

## Run it

```bash
docker compose up -d                      # app: wall :20080, admin :21080, ws :8080
docker compose --profile test up -d       # + isolated test stack + selenium (noVNC :7900)
docker compose run --rm tester all        # unit → smoke → regression → e2e
```
Admin (dev): `admin` / `admin12345`. Config is env-only (`x-php-env` in
docker-compose.yml); a missing variable stops the app at boot, by design.

## Architecture (one comment's journey = the 9 pipeline steps)

```
browser ──POST /comments──▶ frontend (Yii2) ──INSERT──▶ MySQL
   S1 S2                        S2  S3 │
                                       └─PUBLISH comments──▶ Redis ──▶ ws relay (php yii ws/serve:
                                          S5                    S6      Ratchet + clue/redis-react,
                                                                         one ReactPHP loop)
                                                                          │ admin scope: full payload
admin (backend) ◀── WebSocket frame (HMAC token, scope admin) ────────────┘ public scope: published only
   S7 login · S8 list + live rows · S9 edit/hide/delete → published again → every page follows
```

| Path | What |
|---|---|
| `common/models/Comment.php` | AR, validation, normalisation, public/admin projections |
| `common/services/CommentService.php` | the only writer: persist, then publish once |
| `common/realtime/` | `Envelope` (wire format + trace id), `RedisCommentEventPublisher`, `SocketTokenService` (HMAC, scopes), `RelayHub` (WS fan-out policy) |
| `console/controllers/WsController.php` | the WS daemon (crash-only: exits if Redis drops) |
| `frontend/` | public wall `/`, `/comments` (POST create, GET keyset page), likes, `/avatar.svg`, `/demo` |
| `backend/` | admin: login, list (filters), edit, delete, `/ws-token` |
| `common/assets/theme/echo-core.js` | shared client: `Echo.Socket` (reconnect), `Echo.Feed` (card component) |

## Tests and the QA layer

| Suite | Where | Mechanism |
|---|---|---|
| Unit | `common/tests/Unit` | PHPUnit/Codeception, real MySQL for AR, fakes for Redis/Ratchet; property test with self-growing corpus |
| Regression | `frontend|backend/tests/Regression` | Yii2 module in-process, spy publisher |
| Smoke | `blackbox/tests/Smoke` | real HTTP + raw RFC 6455 client against the test stack |
| E2E | `blackbox/tests/E2E` | real Chromium via Selenium |

`qa/steps.php` is the registry of pipeline steps (routes, code paths, required
suites). Tests opt in with `@group step-<ID>`. `qa/qa.php` derives the
traceability matrix, learns from every run (`qa/history.json`), plans minimal
runs from a diff, finds gaps, scaffolds tests and builds shards.

## Rules when changing this project

1. **After any app change run `qa gaps`.** New route or file → claim it in
   `qa/steps.php` (new step or existing one) → `qa scaffold` the missing tests →
   implement → `run_tests` → `qa matrix --strict` must pass.
2. Every state change of a comment goes through `CommentService` (it publishes).
3. No silent fallbacks: missing config throws (`Env`), relay outages are
   reported (`PublishResult`), never faked.
4. Black-box tests only add uniquely-marked data (`uniqueText()`), never truncate.
5. Commit `qa/history.json` and `docs/TRACEABILITY.md` with test changes.
