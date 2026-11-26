<?php

declare(strict_types=1);

namespace blackbox\tests\Support\Helper;

use blackbox\tests\Support\WebSocketClient;
use Codeception\Module;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

/**
 * Knows where the isolated test stack lives and talks to it like an outside
 * client would: plain HTTP with its own cookie jar, raw WebSocket.
 * Independent of PhpBrowser/WebDriver sessions on purpose, so a test can act
 * as "the admin" here while the browser acts as "the visitor".
 */
final class Stack extends Module
{
    protected array $config = [];
    protected array $requiredFields = ['frontend', 'backend', 'wsHost', 'wsPort', 'adminUser', 'adminPassword'];

    private ?Client $adminHttp = null;

    public function frontendUrl(string $path = ''): string
    {
        return rtrim($this->config['frontend'], '/') . $path;
    }

    public function backendUrl(string $path = ''): string
    {
        return rtrim($this->config['backend'], '/') . $path;
    }

    /**
     * @return array{username:string, password:string}
     */
    public function adminCredentials(): array
    {
        return ['username' => $this->config['adminUser'], 'password' => $this->config['adminPassword']];
    }

    /** Unique marker so parallel runs never collide on shared data. */
    public function uniqueText(string $prefix): string
    {
        return $prefix . ' ' . bin2hex(random_bytes(4));
    }

    public function openSocket(string $token): WebSocketClient
    {
        return WebSocketClient::connect($this->config['wsHost'], (int) $this->config['wsPort'], '/?token=' . rawurlencode($token));
    }

    /**
     * The way browsers connect: same origin as the page, /ws/ tunnelled by Apache.
     */
    public function openSocketThroughApp(string $appUrl, string $token): WebSocketClient
    {
        $u = parse_url($appUrl);

        return WebSocketClient::connect($u['host'], (int) ($u['port'] ?? 80), '/ws/?token=' . rawurlencode($token));
    }

    /** Raw handshake with an arbitrary query (for negative tests). */
    public function openSocketRaw(string $path): WebSocketClient
    {
        return WebSocketClient::connect($this->config['wsHost'], (int) $this->config['wsPort'], $path);
    }

    /**
     * Logs into the backend over plain HTTP and returns an admin-scope token.
     */
    public function adminSocketToken(): string
    {
        $http = $this->adminHttp();
        $data = json_decode((string) $http->get($this->backendUrl('/ws-token'))->getBody(), true);
        $this->assertIsArray($data, 'backend /ws-token must answer JSON for a logged-in admin');

        return $data['token'];
    }

    public function publicSocketToken(): string
    {
        $data = json_decode((string) (new Client(['http_errors' => true]))->get($this->frontendUrl('/ws-token'))->getBody(), true);

        return $data['token'];
    }

    /**
     * POST a comment the way the browser JS does (AJAX + CSRF from the page).
     *
     * @return array{status:int, body:array<string, mixed>|null}
     */
    public function postComment(string $author, string $body, bool $demo = false): array
    {
        $jar = new CookieJar();
        $http = new Client(['cookies' => $jar, 'http_errors' => false]);
        $html = (string) $http->get($this->frontendUrl('/'))->getBody();
        preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1] ?? null, 'wall page must expose a CSRF token');

        $form = ['Comment[author_name]' => $author, 'Comment[body]' => $body];
        if ($demo) {
            $form['demo'] = '1';
        }
        $res = $http->post($this->frontendUrl('/comments'), [
            'form_params' => $form,
            'headers' => ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json', 'X-CSRF-Token' => $m[1]],
        ]);

        return ['status' => $res->getStatusCode(), 'body' => json_decode((string) $res->getBody(), true)];
    }

    private function adminHttp(): Client
    {
        if ($this->adminHttp !== null) {
            return $this->adminHttp;
        }
        $http = new Client(['cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => true]);
        $html = (string) $http->get($this->backendUrl('/login'))->getBody();
        preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m);
        $res = $http->post($this->backendUrl('/login'), ['form_params' => [
            '_csrf-backend' => $m[1] ?? '',
            'LoginForm[username]' => $this->config['adminUser'],
            'LoginForm[password]' => $this->config['adminPassword'],
        ]]);
        $this->assertStringContainsString('data-admin-live', (string) $res->getBody(), 'admin login over HTTP failed');

        return $this->adminHttp = $http;
    }
}
