<?php

declare(strict_types=1);

namespace common\realtime;

use Closure;
use InvalidArgumentException;

/**
 * Short-lived, HMAC-signed tokens that authorise a WebSocket handshake.
 *
 *   token = base64url(json{s,u,e,n}) "." base64url(hmac_sha256(payload, secret))
 *
 * Scopes:
 *  - admin  — issued by the backend to a logged-in administrator; receives the
 *             full payload of every event (including hidden comments and IPs).
 *  - public — issued by the frontend to any visitor; receives only published
 *             comments, with private fields stripped.
 *
 * The web apps and the relay share the secret (WS_TOKEN_SECRET); the relay
 * never talks to the database or the session store.
 */
final class SocketTokenService
{
    public const SCOPE_ADMIN = 'admin';
    public const SCOPE_PUBLIC = 'public';
    public const SCOPES = [self::SCOPE_ADMIN, self::SCOPE_PUBLIC];

    private readonly Closure $clock;

    public function __construct(
        #[\SensitiveParameter] private readonly string $secret,
        ?Closure $clock = null,
    ) {
        if (strlen($secret) < 16) {
            throw new InvalidArgumentException('WS token secret must be at least 16 bytes long.');
        }
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function issue(string $scope, int $subject, int $ttlSeconds): string
    {
        if (!in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException("Unknown socket scope '{$scope}'.");
        }
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('Token TTL must be positive.');
        }

        $payload = self::b64encode(json_encode([
            's' => $scope,
            'u' => $subject,
            'e' => ($this->clock)() + $ttlSeconds,
            'n' => bin2hex(random_bytes(6)),
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . self::b64encode($this->sign($payload));
    }

    /**
     * Returns the grant, or null for any malformed, forged or expired token.
     */
    public function verify(string $token): ?SocketGrant
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        [$payload, $signature] = $parts;

        $given = self::b64decode($signature);
        if ($given === null || !hash_equals($this->sign($payload), $given)) {
            return null;
        }

        $json = self::b64decode($payload);
        $claims = $json === null ? null : json_decode($json, true);
        if (
            !is_array($claims)
            || !in_array($claims['s'] ?? null, self::SCOPES, true)
            || !is_int($claims['u'] ?? null)
            || !is_int($claims['e'] ?? null)
        ) {
            return null;
        }

        if ($claims['e'] < ($this->clock)()) {
            return null;
        }

        return new SocketGrant($claims['s'], $claims['u'], $claims['e']);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret, true);
    }

    private static function b64encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64decode(string $text): ?string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $text)) {
            return null;
        }
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
