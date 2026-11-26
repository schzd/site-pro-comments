<?php

declare(strict_types=1);

namespace common\tests\Support\Realtime;

use common\realtime\CommentEventPublisherInterface;
use common\realtime\Envelope;
use common\realtime\PublishResult;

/**
 * Test double for the realtime channel, wired in common/config/test.php.
 *
 * Records every published event (statically, so it survives the per-request
 * application rebuild of the Yii2 Codeception module) and can be switched
 * into "Redis is down" mode to exercise the failure path.
 */
final class SpyCommentEventPublisher implements CommentEventPublisherInterface
{
    /** @var list<Envelope> */
    private static array $published = [];
    private static bool $failing = false;

    public function publish(string $event, array $data): PublishResult
    {
        $envelope = Envelope::create($event, $data);
        if (self::$failing) {
            return PublishResult::failed('spy: simulated Redis outage', $envelope);
        }
        self::$published[] = $envelope;

        return PublishResult::delivered(1, $envelope);
    }

    public static function reset(): void
    {
        self::$published = [];
        self::$failing = false;
    }

    public static function failNextPublishes(bool $failing = true): void
    {
        self::$failing = $failing;
    }

    /**
     * @return list<Envelope>
     */
    public static function published(?string $event = null): array
    {
        return array_values(array_filter(
            self::$published,
            static fn (Envelope $e): bool => $event === null || $e->event === $event,
        ));
    }

    public static function last(): ?Envelope
    {
        return self::$published === [] ? null : self::$published[array_key_last(self::$published)];
    }
}
