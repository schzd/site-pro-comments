<?php

declare(strict_types=1);

namespace common\realtime;

/**
 * Outcome of handing an event to the realtime channel.
 *
 * The comment itself is always persisted first; this object reports — and the
 * API returns — whether the live fan-out actually happened. A failure is never
 * hidden: it is logged, returned to the caller and visible to the admin as an
 * OFFLINE socket indicator.
 */
final class PublishResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly int $subscribers,
        public readonly string $trace,
        public readonly float $publishedAt,
        public readonly ?string $error,
    ) {
    }

    public static function delivered(int $subscribers, Envelope $envelope): self
    {
        return new self(true, $subscribers, $envelope->trace, $envelope->publishedAt, null);
    }

    public static function failed(string $error, Envelope $envelope): self
    {
        return new self(false, 0, $envelope->trace, $envelope->publishedAt, $error);
    }

    /**
     * Public projection. The internal error text stays in the logs.
     *
     * @return array{delivered:bool, subscribers:int, trace:string, published_at:float, error:?string}
     */
    public function toArray(): array
    {
        return [
            'delivered' => $this->delivered,
            'subscribers' => $this->subscribers,
            'trace' => $this->trace,
            'published_at' => round($this->publishedAt, 3),
            'error' => $this->delivered ? null : 'realtime relay unavailable',
        ];
    }
}
