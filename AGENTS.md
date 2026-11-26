# Agents

The agent guide for this repository is **[CLAUDE.md](CLAUDE.md)** — architecture,
commands, the pipeline registry and the rules for extending the tests. It
applies to any coding agent (Claude Code, Codex, Cursor, …).

MCP server: `echo-qa` in [.mcp.json](.mcp.json) (stdio, runs in Docker).
Without MCP support, the same capabilities are plain commands:
`docker compose run --rm tester <unit|smoke|regression|e2e|all>` and
`docker compose run --rm tester qa <gaps|plan|scaffold|matrix|shards|history>`.
