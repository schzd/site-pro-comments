<?php

declare(strict_types=1);

namespace common\realtime;

use InvalidArgumentException;
use JsonException;

/**
 * Wire format of a message on the Redis channel.
 *
 *   {"v":1,"event":"comment.created","trace":"9f2c…","published_at":1732620000.123,"data":{…}}
 *
 * `trace` lets a client correlate the HTTP response of its own POST with the
 * WebSocket frame that the relay later delivers (used by the demo trace view
 * and by the E2E suite).
 */
final class Envelope
{
    public const VERSION = 1;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $event,
        public readonly array $data,
        public readonly string $trace,
        public readonly float $publishedAt,
    ) {
        if (!in_array($event, CommentEvent::ALL, true)) {
            throw new InvalidArgumentException("Unknown comment event '{$event}'.");
        }
        if (!preg_match('/^[a-f0-9]{16}$/', $trace)) {
            throw new InvalidArgumentException('Trace id must be 16 lowercase hex characters.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(string $event, array $data, ?float $now = null): self
    {
        return new self($event, $data, bin2hex(random_bytes(8)), $now ?? microtime(true));
    }

    public function encode(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{v:int, event:string, trace:string, published_at:float, data:array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'event' => $this->event,
            'trace' => $this->trace,
            'published_at' => round($this->publishedAt, 3),
            'data' => $this->data,
        ];
    }

    /**
     * Strict decoding: anything that is not a well-formed v1 envelope is rejected,
     * so the relay never forwards foreign or garbled messages to browsers.
     *
     * @throws InvalidArgumentException
     */
    public static function decode(string $raw): self
    {
        try {
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Envelope is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($payload) || ($payload['v'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Envelope has no supported version.');
        }
        if (!is_string($payload['event'] ?? null) || !is_string($payload['trace'] ?? null)) {
            throw new InvalidArgumentException('Envelope is missing event or trace.');
        }
        if (!is_int($payload['published_at'] ?? null) && !is_float($payload['published_at'] ?? null)) {
            throw new InvalidArgumentException('Envelope is missing published_at.');
        }
        if (!is_array($payload['data'] ?? null)) {
            throw new InvalidArgumentException('Envelope data must be an object.');
        }

        return new self($payload['event'], $payload['data'], $payload['trace'], (float) $payload['published_at']);
    }
}
