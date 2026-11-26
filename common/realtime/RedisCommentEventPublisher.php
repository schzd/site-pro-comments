<?php

declare(strict_types=1);

namespace common\realtime;

use Yii;
use yii\redis\Connection;

/**
 * Publishes comment events on a Redis pub/sub channel.
 *
 * PUBLISH returns the number of subscribers that received the message. The WS
 * relay is the only subscriber, so `subscribers === 0` means "Redis is up but
 * the relay is not listening" — a distinct, observable failure mode.
 */
final class RedisCommentEventPublisher implements CommentEventPublisherInterface
{
    public function __construct(
        private readonly Connection $redis,
        private readonly string $channel,
    ) {
    }

    public function publish(string $event, array $data): PublishResult
    {
        $envelope = Envelope::create($event, $data);

        try {
            $subscribers = (int) $this->redis->executeCommand('PUBLISH', [$this->channel, $envelope->encode()]);
        } catch (\Exception $e) {
            Yii::error(
                sprintf('Redis PUBLISH %s on "%s" failed: %s', $event, $this->channel, $e->getMessage()),
                'realtime',
            );
            // Drop the broken socket so the next request reconnects instead of reusing it.
            $this->redis->close();

            return PublishResult::failed($e->getMessage(), $envelope);
        }

        if ($subscribers === 0) {
            Yii::warning(sprintf('Redis PUBLISH %s on "%s" reached 0 subscribers — is the ws relay running?', $event, $this->channel), 'realtime');
        }

        return PublishResult::delivered($subscribers, $envelope);
    }

    public function channel(): string
    {
        return $this->channel;
    }
}
