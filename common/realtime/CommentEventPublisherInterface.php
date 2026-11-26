<?php

declare(strict_types=1);

namespace common\realtime;

interface CommentEventPublisherInterface
{
    /**
     * Publish one event. Must not throw on transport failure — it returns a
     * failed result instead, so persistence is never rolled back by the
     * (secondary) live notification.
     *
     * @param array<string, mixed> $data
     */
    public function publish(string $event, array $data): PublishResult;
}
