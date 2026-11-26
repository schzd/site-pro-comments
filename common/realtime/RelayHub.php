<?php

declare(strict_types=1);

namespace common\realtime;

use Closure;
use common\models\Comment;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;

/**
 * The WebSocket side of the relay: authenticates sockets and fans a Redis
 * envelope out to them, projected per scope.
 *
 * Kept free of any I/O of its own (no loop, no Redis) so the whole routing
 * policy is unit-testable with fake connections.
 */
final class RelayHub implements MessageComponentInterface
{
    /** Application-defined close code (4000–4999 range): bad or missing token. */
    public const CLOSE_UNAUTHORIZED = 4401;

    /** Fields a public (anonymous) socket may ever see. */
    public const PUBLIC_FIELDS = [
        'id', 'author_name', 'handle', 'avatar_url', 'body', 'likes', 'is_demo', 'created_at', 'created_at_iso',
    ];

    /** @var SplObjectStorage<ConnectionInterface, SocketGrant> */
    private SplObjectStorage $clients;

    private readonly Closure $clock;

    /**
     * @param Closure(string, string): void $log  receives (level, message)
     */
    public function __construct(
        private readonly SocketTokenService $tokens,
        private readonly Closure $log,
        ?Closure $clock = null,
    ) {
        $this->clients = new SplObjectStorage();
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $request = $conn->httpRequest ?? null;
        $grant = $request instanceof RequestInterface ? $this->authorize($request) : null;

        if ($grant === null) {
            ($this->log)('warning', sprintf('socket #%s rejected: missing/invalid token', $conn->resourceId ?? '?'));
            $conn->send($this->json(['event' => 'system.error', 'data' => ['code' => self::CLOSE_UNAUTHORIZED, 'reason' => 'unauthorized']]));
            $conn->close(self::CLOSE_UNAUTHORIZED);

            return;
        }

        $this->clients->attach($conn, $grant);
        ($this->log)('info', sprintf('socket #%s open scope=%s subject=%d %s', $conn->resourceId ?? '?', $grant->scope, $grant->subject, $this->describeCounts()));

        $conn->send($this->json([
            'event' => 'system.hello',
            'data' => ['scope' => $grant->scope, 'clients' => $this->counts(), 'server_time' => round(($this->clock)(), 3)],
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void
    {
        // Sockets are receive-only. The single supported client message is a
        // latency probe, answered with the relay's clock.
        if (trim((string) $msg) === 'ping' && $this->clients->contains($from)) {
            $from->send($this->json(['event' => 'system.pong', 'data' => ['server_time' => round(($this->clock)(), 3)]]));
        }
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if ($this->clients->contains($conn)) {
            $this->clients->detach($conn);
            ($this->log)('info', sprintf('socket #%s closed %s', $conn->resourceId ?? '?', $this->describeCounts()));
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        ($this->log)('error', sprintf('socket #%s error: %s', $conn->resourceId ?? '?', $e->getMessage()));
        $conn->close();
    }

    /**
     * Fan one raw Redis message out. Returns how many sockets received it.
     */
    public function relay(string $raw): int
    {
        try {
            $envelope = Envelope::decode($raw);
        } catch (InvalidArgumentException $e) {
            ($this->log)('warning', 'dropped malformed envelope: ' . $e->getMessage());

            return 0;
        }

        $deliveries = [];
        foreach ($this->clients as $conn) {
            $grant = $this->clients[$conn];
            $frame = $grant->isAdmin() ? $this->adminFrame($envelope) : $this->publicFrame($envelope);
            if ($frame !== null) {
                $deliveries[] = [$conn, $frame];
            }
        }

        $relay = ['relayed_at' => round(($this->clock)(), 3), 'recipients' => count($deliveries)];
        foreach ($deliveries as [$conn, $frame]) {
            $frame['relay'] = $relay;
            $conn->send($this->json($frame));
        }

        ($this->log)('info', sprintf('relayed %s trace=%s -> %d socket(s)', $envelope->event, $envelope->trace, count($deliveries)));

        return count($deliveries);
    }

    /**
     * @return array{admin:int, public:int}
     */
    public function counts(): array
    {
        $counts = ['admin' => 0, 'public' => 0];
        foreach ($this->clients as $conn) {
            $counts[$this->clients[$conn]->isAdmin() ? 'admin' : 'public']++;
        }

        return $counts;
    }

    public function authorize(RequestInterface $request): ?SocketGrant
    {
        parse_str($request->getUri()->getQuery(), $query);
        $token = $query['token'] ?? null;

        return is_string($token) && $token !== '' ? $this->tokens->verify($token) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function adminFrame(Envelope $envelope): array
    {
        return $envelope->toArray();
    }

    /**
     * Public sockets only learn about published comments, without private fields.
     * Hiding a comment is announced to them as a deletion.
     *
     * @return array<string, mixed>|null
     */
    private function publicFrame(Envelope $envelope): ?array
    {
        $frame = $envelope->toArray();
        $data = $envelope->data;

        switch ($envelope->event) {
            case CommentEvent::CREATED:
            case CommentEvent::LIKED:
                if (($data['status'] ?? null) !== Comment::STATUS_PUBLISHED) {
                    return null;
                }
                break;
            case CommentEvent::UPDATED:
                if (($data['status'] ?? null) !== Comment::STATUS_PUBLISHED) {
                    $frame['event'] = CommentEvent::DELETED;
                }
                break;
        }

        $frame['data'] = match ($frame['event']) {
            CommentEvent::DELETED => ['id' => $data['id'] ?? null, 'is_demo' => (bool) ($data['is_demo'] ?? false)],
            CommentEvent::PURGED => ['ids' => array_values($data['ids'] ?? []), 'is_demo' => true],
            CommentEvent::LIKED => ['id' => $data['id'] ?? null, 'likes' => $data['likes'] ?? 0, 'is_demo' => (bool) ($data['is_demo'] ?? false)],
            default => array_intersect_key($data, array_flip(self::PUBLIC_FIELDS)),
        };

        return $frame;
    }

    private function describeCounts(): string
    {
        $c = $this->counts();

        return sprintf('(admin=%d public=%d)', $c['admin'], $c['public']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
