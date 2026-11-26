<?php

declare(strict_types=1);

namespace common\tests\Unit\Realtime;

use Codeception\Test\Unit;
use common\realtime\CommentEvent;
use common\realtime\Envelope;
use InvalidArgumentException;

/**
 * Step S5 — the wire format between the app and the relay.
 *
 * @group step-S5
 */
final class EnvelopeTest extends Unit
{
    public function testRoundTrip(): void
    {
        $e = Envelope::create(CommentEvent::CREATED, ['id' => 7, 'body' => 'Привет <b>'], 1700000000.1234);
        $decoded = Envelope::decode($e->encode());

        $this->assertSame(CommentEvent::CREATED, $decoded->event);
        $this->assertSame(['id' => 7, 'body' => 'Привет <b>'], $decoded->data);
        $this->assertSame($e->trace, $decoded->trace);
        $this->assertSame(1700000000.123, $decoded->publishedAt);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $e->trace);
    }

    public function testEncodedFormIsStable(): void
    {
        $e = new Envelope(CommentEvent::DELETED, ['id' => 1], '0123456789abcdef', 1.5);
        $this->assertSame('{"v":1,"event":"comment.deleted","trace":"0123456789abcdef","published_at":1.5,"data":{"id":1}}', $e->encode());
    }

    public function testTraceIdsAreUnique(): void
    {
        $traces = array_map(static fn () => Envelope::create(CommentEvent::CREATED, [])->trace, range(1, 200));
        $this->assertCount(200, array_unique($traces));
    }

    /**
     * @dataProvider garbage
     */
    public function testDecodeRejectsForeignOrBrokenMessages(string $raw): void
    {
        $this->expectException(InvalidArgumentException::class);
        Envelope::decode($raw);
    }

    public static function garbage(): array
    {
        return [
            'not json' => ['hello'],
            'json scalar' => ['42'],
            'wrong version' => ['{"v":2,"event":"comment.created","trace":"0123456789abcdef","published_at":1,"data":{}}'],
            'unknown event' => ['{"v":1,"event":"user.deleted","trace":"0123456789abcdef","published_at":1,"data":{}}'],
            'bad trace' => ['{"v":1,"event":"comment.created","trace":"xyz","published_at":1,"data":{}}'],
            'no timestamp' => ['{"v":1,"event":"comment.created","trace":"0123456789abcdef","data":{}}'],
            'data not object' => ['{"v":1,"event":"comment.created","trace":"0123456789abcdef","published_at":1,"data":"x"}'],
            'too deep' => ['{"v":1,"event":"comment.created","trace":"0123456789abcdef","published_at":1,"data":' . str_repeat('[', 40) . str_repeat(']', 40) . '}'],
        ];
    }
}
