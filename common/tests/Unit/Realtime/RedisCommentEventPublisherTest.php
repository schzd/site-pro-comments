<?php

declare(strict_types=1);

namespace common\tests\Unit\Realtime;

use Codeception\Test\Unit;
use common\realtime\CommentEvent;
use common\realtime\Envelope;
use common\realtime\RedisCommentEventPublisher;
use yii\db\Exception as DbException;
use yii\redis\Connection;

/**
 * Step S5 — publishing to Redis, including the failure modes.
 *
 * @group step-S5
 */
final class RedisCommentEventPublisherTest extends Unit
{
    public function testPublishesEnvelopeOnConfiguredChannel(): void
    {
        $redis = new FakeRedis();
        $redis->reply = 1;
        $result = (new RedisCommentEventPublisher($redis, 'comments'))->publish(CommentEvent::CREATED, ['id' => 9]);

        $this->assertCount(1, $redis->calls);
        [$command, [$channel, $payload]] = $redis->calls[0];
        $this->assertSame('PUBLISH', $command);
        $this->assertSame('comments', $channel);
        $envelope = Envelope::decode($payload);
        $this->assertSame(['id' => 9], $envelope->data);

        $this->assertTrue($result->delivered);
        $this->assertSame(1, $result->subscribers);
        $this->assertSame($envelope->trace, $result->trace, 'the HTTP response must carry the same trace id as the frame');
    }

    public function testZeroSubscribersIsDeliveredButObservable(): void
    {
        $redis = new FakeRedis();
        $redis->reply = 0;
        $result = (new RedisCommentEventPublisher($redis, 'comments'))->publish(CommentEvent::CREATED, []);

        $this->assertTrue($result->delivered);
        $this->assertSame(0, $result->subscribers, 'Redis up but relay not listening must be visible, not hidden');
    }

    public function testRedisOutageReturnsFailedResultInsteadOfThrowing(): void
    {
        $redis = new FakeRedis();
        $redis->throw = new DbException('Failed to open redis DB connection');
        $result = (new RedisCommentEventPublisher($redis, 'comments'))->publish(CommentEvent::CREATED, []);

        $this->assertFalse($result->delivered);
        $this->assertSame(0, $result->subscribers);
        $this->assertSame('realtime relay unavailable', $result->toArray()['error'], 'public error text must not leak internals');
        $this->assertTrue($redis->closed, 'a broken socket must be dropped so the next request reconnects');
    }
}

final class FakeRedis extends Connection
{
    /** @var list<array{0:string,1:array}> */
    public array $calls = [];
    public mixed $reply = 1;
    public ?\Exception $throw = null;
    public bool $closed = false;

    public function executeCommand($name, $params = [])
    {
        if ($this->throw) {
            throw $this->throw;
        }
        $this->calls[] = [$name, $params];

        return $this->reply;
    }

    public function close()
    {
        $this->closed = true;
    }
}
