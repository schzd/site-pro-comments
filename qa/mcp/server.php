<?php

declare(strict_types=1);

/**
 * echo-qa — MCP server (stdio, JSON-RPC 2.0) that lets an AI agent explore,
 * verify and extend this project against the LIVE Docker stack.
 *
 * Started by `.mcp.json` as:  docker compose --profile mcp run --rm -T --no-deps mcp
 * Runs inside the compose network, so it reaches frontend/backend/ws/redis
 * (dev stack) and frontend-test/backend-test/ws-test (isolated test stack).
 *
 * stdout carries protocol messages only; diagnostics go to stderr.
 */

use blackbox\tests\Support\WebSocketClient;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../../vendor/autoload.php';

const ROOT = __DIR__ . '/../..';
const PROTOCOL = '2025-06-18';

// ------------------------------------------------------------------ targets

function target(string $env): array
{
    return $env === 'e2e'
        ? [
            'frontend' => getenv('E2E_FRONTEND_URL'), 'backend' => getenv('E2E_BACKEND_URL'),
            'ws' => [getenv('E2E_WS_HOST'), (int) getenv('E2E_WS_PORT')], 'channel' => 'comments_e2e',
            'user' => getenv('ADMIN_USERNAME'), 'password' => getenv('ADMIN_PASSWORD'),
        ]
        : [
            'frontend' => getenv('DEV_FRONTEND_URL'), 'backend' => getenv('DEV_BACKEND_URL'),
            'ws' => [getenv('DEV_WS_HOST'), (int) getenv('DEV_WS_PORT')], 'channel' => 'comments',
            'user' => getenv('DEV_ADMIN_USERNAME'), 'password' => getenv('DEV_ADMIN_PASSWORD'),
        ];
}

// ------------------------------------------------------------------ tools

function tools(): array
{
    $env = ['type' => 'string', 'enum' => ['dev', 'e2e'], 'description' => 'dev = the app on localhost:20080/21080; e2e = the isolated test stack', 'default' => 'dev'];

    return [
        'project_overview' => [
            'description' => 'Start here. What this project is, how it is built, where things live, the 9-step comment pipeline and how tests guard it.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        'stack_status' => [
            'description' => 'Health of the running stack: HTTP for wall/admin, WebSocket handshake + hello, Redis subscriber count on the comments channel.',
            'inputSchema' => ['type' => 'object', 'properties' => ['env' => $env]],
        ],
        'post_comment' => [
            'description' => 'POST a real comment through the public endpoint (with CSRF, like the browser). Returns the API response incl. realtime delivery and trace id.',
            'inputSchema' => ['type' => 'object', 'required' => ['body'], 'properties' => [
                'env' => $env,
                'author' => ['type' => 'string', 'description' => 'Optional display name; blank = generated guest handle'],
                'body' => ['type' => 'string'],
            ]],
        ],
        'watch_admin_socket' => [
            'description' => 'Log in as admin, open the admin-scope WebSocket and collect frames. With probe=true it posts a comment and measures POST→frame latency: live proof of the real-time path.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'env' => $env,
                'seconds' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 30, 'default' => 5],
                'probe' => ['type' => 'boolean', 'default' => true],
            ]],
        ],
        'run_tests' => [
            'description' => 'Run a suite in Docker (unit | smoke | regression | e2e | all). Extra codecept args allowed, e.g. ["-g","step-S4"] or ["--filter","likeIs"]. Results feed the learning history.',
            'inputSchema' => ['type' => 'object', 'required' => ['suite'], 'properties' => [
                'suite' => ['type' => 'string', 'enum' => ['unit', 'smoke', 'regression', 'e2e', 'all']],
                'args' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
        ],
        'qa' => [
            'description' => 'Test intelligence (qa/qa.php). matrix = step→tests; gaps = what nothing guards (run after ANY app change); plan = minimal tests for the current diff; scaffold = new test wired to a step; shards = parallel split; history = slow/flaky/failing; steps = registry.',
            'inputSchema' => ['type' => 'object', 'required' => ['command'], 'properties' => [
                'command' => ['type' => 'string', 'enum' => ['steps', 'matrix', 'gaps', 'plan', 'scaffold', 'shards', 'history']],
                'args' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'e.g. ["--step=S4","--suite=regression","--app=frontend","--name=LikeBurstCest"] or ["--base=origin/main"] or ["--suite=e2e","--n=3"]'],
            ]],
        ],
        'explain_step' => [
            'description' => 'One pipeline step in depth: what it does, the code implementing it (with file list), and every test that locks it with its last result.',
            'inputSchema' => ['type' => 'object', 'required' => ['step'], 'properties' => [
                'step' => ['type' => 'string', 'description' => 'S1..S9, DEMO or CONFIG'],
            ]],
        ],
    ];
}

function callTool(string $name, array $a): string
{
    return match ($name) {
        'project_overview' => projectOverview(),
        'stack_status' => stackStatus($a['env'] ?? 'dev'),
        'post_comment' => json_encode(postComment(target($a['env'] ?? 'dev'), (string) ($a['author'] ?? ''), (string) $a['body']), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'watch_admin_socket' => watchAdminSocket($a['env'] ?? 'dev', (int) ($a['seconds'] ?? 5), (bool) ($a['probe'] ?? true)),
        'run_tests' => shell(array_merge(['bash', 'docker/tester/run.sh', $a['suite']], array_map('strval', $a['args'] ?? [])), 140),
        'qa' => shell(array_merge(['php', 'qa/qa.php', $a['command']], array_map('strval', $a['args'] ?? [])), 200),
        'explain_step' => explainStep((string) $a['step']),
        default => throw new InvalidArgumentException("unknown tool {$name}"),
    };
}

function projectOverview(): string
{
    $claude = (string) file_get_contents(ROOT . '/CLAUDE.md');

    return $claude . "\n\n---\nPipeline registry (php qa/qa.php steps):\n" . shell(['php', 'qa/qa.php', 'steps'], 80);
}

function stackStatus(string $env): string
{
    $t = target($env);
    $http = new Client(['http_errors' => false, 'timeout' => 5]);
    $lines = ["stack: {$env}"];
    foreach (['wall' => $t['frontend'] . '/', 'admin login' => $t['backend'] . '/login', 'demo' => $t['frontend'] . '/demo'] as $label => $url) {
        try {
            $t0 = microtime(true);
            $code = $http->get($url)->getStatusCode();
            $lines[] = sprintf('%-12s %s %d (%d ms)', $label, $url, $code, (microtime(true) - $t0) * 1000);
        } catch (Throwable $e) {
            $lines[] = sprintf('%-12s %s DOWN: %s', $label, $url, $e->getMessage());
        }
    }
    try {
        $token = json_decode((string) $http->get($t['frontend'] . '/ws-token')->getBody(), true)['token'] ?? '';
        $ws = WebSocketClient::connect($t['ws'][0], $t['ws'][1], '/?token=' . rawurlencode($token));
        $hello = $ws->receiveJson(3);
        $ws->close();
        $lines[] = sprintf('websocket    %s:%d hello=%s clients=%s', $t['ws'][0], $t['ws'][1], $hello['data']['scope'] ?? 'none', json_encode($hello['data']['clients'] ?? null));
    } catch (Throwable $e) {
        $lines[] = 'websocket    DOWN: ' . $e->getMessage();
    }
    try {
        $lines[] = sprintf('redis        PUBSUB NUMSUB %s = %d (the ws relay must be 1)', $t['channel'], redisNumSub($t['channel']));
    } catch (Throwable $e) {
        $lines[] = 'redis        DOWN: ' . $e->getMessage();
    }

    return implode("\n", $lines);
}

function redisNumSub(string $channel): int
{
    $s = @stream_socket_client('tcp://' . getenv('REDIS_HOST') . ':' . getenv('REDIS_PORT'), $no, $err, 3);
    if ($s === false) {
        throw new RuntimeException($err);
    }
    fwrite($s, "*3\r\n\$6\r\nPUBSUB\r\n\$6\r\nNUMSUB\r\n\$" . strlen($channel) . "\r\n{$channel}\r\n");
    $resp = '';
    while (!preg_match('/:(\d+)\r\n$/', $resp) && !feof($s)) {
        $resp .= fread($s, 512);
    }
    fclose($s);
    preg_match('/:(\d+)\r\n$/', $resp, $m);

    return (int) ($m[1] ?? 0);
}

function postComment(array $t, string $author, string $body): array
{
    $http = new Client(['cookies' => new CookieJar(), 'http_errors' => false, 'timeout' => 10]);
    preg_match('/name="csrf-token" content="([^"]+)"/', (string) $http->get($t['frontend'] . '/')->getBody(), $m);
    $t0 = microtime(true);
    $res = $http->post($t['frontend'] . '/comments', [
        'form_params' => ['Comment[author_name]' => $author, 'Comment[body]' => $body],
        'headers' => ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json', 'X-CSRF-Token' => $m[1] ?? ''],
    ]);

    return ['http_status' => $res->getStatusCode(), 'ms' => (int) ((microtime(true) - $t0) * 1000), 'response' => json_decode((string) $res->getBody(), true)];
}

function watchAdminSocket(string $env, int $seconds, bool $probe): string
{
    $t = target($env);
    $http = new Client(['cookies' => new CookieJar(), 'http_errors' => false, 'timeout' => 10]);
    preg_match('/name="csrf-token" content="([^"]+)"/', (string) $http->get($t['backend'] . '/login')->getBody(), $m);
    $http->post($t['backend'] . '/login', ['form_params' => ['_csrf-backend' => $m[1] ?? '', 'LoginForm[username]' => $t['user'], 'LoginForm[password]' => $t['password']]]);
    $grant = json_decode((string) $http->get($t['backend'] . '/ws-token')->getBody(), true);
    if (!isset($grant['token'])) {
        return 'admin login failed — check DEV_ADMIN_* / ADMIN_* credentials';
    }

    $ws = WebSocketClient::connect($t['ws'][0], $t['ws'][1], '/?token=' . rawurlencode($grant['token']));
    $frames = [$ws->receiveJson(3)];
    $report = [];
    if ($probe) {
        $text = 'mcp probe ' . bin2hex(random_bytes(3));
        $t0 = microtime(true);
        $post = postComment($t, 'MCP Probe', $text);
        $trace = $post['response']['realtime']['trace'] ?? null;
        $frame = $ws->waitFor(static fn (array $f) => ($f['trace'] ?? null) === $trace, $seconds);
        $report[] = $frame
            ? sprintf('PROBE OK: "%s" posted (HTTP %d) and arrived on the admin socket %d ms after the POST started, trace %s, relayed to %d socket(s).',
                $text, $post['http_status'], (microtime(true) - $t0) * 1000, $trace, $frame['relay']['recipients'] ?? 0)
            : sprintf('PROBE FAILED: posted (HTTP %d, realtime=%s) but no frame with trace %s within %ds.', $post['http_status'], json_encode($post['response']['realtime'] ?? null), (string) $trace, $seconds);
        $frames[] = $frame;
    }
    $deadline = microtime(true) + ($probe ? 1 : $seconds);
    while (($left = $deadline - microtime(true)) > 0 && ($f = $ws->receiveJson($left)) !== null) {
        $frames[] = $f;
    }
    $ws->close();

    return implode("\n", $report) . "\n\nFrames received:\n" . json_encode(array_values(array_filter($frames)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function explainStep(string $id): string
{
    $steps = require ROOT . '/qa/steps.php';
    if (!isset($steps[$id])) {
        return 'Unknown step. Known: ' . implode(', ', array_keys($steps));
    }
    $s = $steps[$id];
    $files = [];
    foreach ($s['paths'] as $glob) {
        foreach (glob(ROOT . '/' . $glob) ?: [] as $f) {
            $files[] = substr(realpath($f), strlen(realpath(ROOT)) + 1);
        }
    }
    $matrix = (string) @file_get_contents(ROOT . '/docs/TRACEABILITY.md');
    preg_match('/## ' . preg_quote($id, '/') . ' — .*?(?=\n## |\n\\\\\*|$)/s', $matrix, $m);

    return "{$id} — {$s['title']}\n\nRoutes: " . (implode(', ', $s['routes'] ?? []) ?: '(none: internal step)')
        . "\nRequired suites: " . implode(', ', $s['require'])
        . "\nTest group: @group " . ($s['group'] ?? 'step-' . $id)
        . "\n\nImplementation files:\n  - " . implode("\n  - ", array_unique($files))
        . "\n\nGuarding tests (last recorded run):\n" . ($m[0] ?? '(run `qa matrix` first)');
}

function shell(array $argv, int $tailLines): string
{
    $cmd = 'cd ' . escapeshellarg(ROOT) . ' && ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    exec($cmd, $out, $code);
    $out = array_map(static fn ($l) => preg_replace('/\e\[[\d;]*m/', '', $l), $out);
    $out = array_values(array_filter($out, static fn ($l) => !str_contains($l, 'Switching uid') && !str_contains($l, 'usermod:')));
    if (count($out) > $tailLines) {
        $out = array_merge(['… (' . (count($out) - $tailLines) . ' lines omitted)'], array_slice($out, -$tailLines));
    }

    return implode("\n", $out) . "\n[exit code {$code}]";
}

// ------------------------------------------------------------------ JSON-RPC loop

function reply(int|string $id, array $result): void
{
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

function replyError(int|string|null $id, int $code, string $message): void
{
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]) . "\n");
}

while (($line = fgets(STDIN)) !== false) {
    $msg = json_decode(trim($line), true);
    if (!is_array($msg) || !isset($msg['method'])) {
        continue;
    }
    $id = $msg['id'] ?? null;
    $params = $msg['params'] ?? [];
    if ($id === null) {
        continue; // notification (e.g. notifications/initialized)
    }
    try {
        switch ($msg['method']) {
            case 'initialize':
                reply($id, [
                    'protocolVersion' => $params['protocolVersion'] ?? PROTOCOL,
                    'capabilities' => ['tools' => new stdClass()],
                    'serverInfo' => ['name' => 'echo-qa', 'version' => '1.0.0'],
                    'instructions' => 'Echo: Yii2 comment wall with real-time admin over WebSockets. Call project_overview first. '
                        . 'To prove the live path call watch_admin_socket. After ANY change to the app call qa{command:"gaps"}, '
                        . 'then qa{command:"scaffold"} for each gap, implement, run_tests, and qa{command:"matrix"}.',
                ]);
                break;
            case 'ping':
                reply($id, []);
                break;
            case 'tools/list':
                $list = [];
                foreach (tools() as $name => $def) {
                    $list[] = ['name' => $name] + $def;
                }
                reply($id, ['tools' => $list]);
                break;
            case 'tools/call':
                try {
                    $text = callTool((string) $params['name'], (array) ($params['arguments'] ?? []));
                    reply($id, ['content' => [['type' => 'text', 'text' => $text]]]);
                } catch (Throwable $e) {
                    reply($id, ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true]);
                }
                break;
            default:
                replyError($id, -32601, 'Method not found: ' . $msg['method']);
        }
    } catch (Throwable $e) {
        replyError($id, -32603, $e->getMessage());
    }
}
