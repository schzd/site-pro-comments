<?php

declare(strict_types=1);

namespace common\tests\Unit\Realtime;

use Codeception\Test\Unit;
use common\realtime\CommentEvent;
use common\realtime\Envelope;
use common\realtime\RelayHub;
use common\realtime\SocketTokenService;
use GuzzleHttp\Psr7\Request;
use Ratchet\ConnectionInterface;

/**
 * Step S6 — the relay authenticates sockets and fans events out per scope.
 *
 * @group step-S6
 */
final class RelayHubTest extends Unit
{
    private SocketTokenService $tokens;
    private RelayHub $hub;
    /** @var list<array{0:string,1:string}> */
    private array $logs = [];

    protected function _before(): void
    {
        $this->tokens = new SocketTokenService('relay-test-secret-0123456789');
        $this->hub = new RelayHub($this->tokens, function (string $level, string $msg): void {
            $this->logs[] = [$level, $msg];
        }, static fn (): float => 1700000000.5);
    }

    private function connect(?string $scope): FakeConnection
    {
        $token = $scope === null ? 'garbage' : $this->tokens->issue($scope, 7, 60);
        $conn = new FakeConnection(new Request('GET', 'ws://relay/?token=' . urlencode($token)));
        $this->hub->onOpen($conn);

        return $conn;
    }

    private function adminComment(array $override = []): array
    {
        return $override + [
            'id' => 11, 'author_name' => 'Ada', 'handle' => 'ada', 'avatar_url' => 'http://f/avatar.svg?name=ada',
            'body' => 'hi', 'likes' => 0, 'is_demo' => false, 'created_at' => 1, 'created_at_iso' => 'x',
            'status' => 10, 'status_label' => 'published', 'ip' => '10.9.9.9', 'updated_at' => 1,
        ];
    }

    private function raw(string $event, array $data): string
    {
        return (new Envelope($event, $data, 'abcdef0123456789', 1700000000.25))->encode();
    }

    public function testRejectsSocketWithoutValidTokenUsingCloseCode4401(): void
    {
        $conn = $this->connect(null);
        $this->assertSame(RelayHub::CLOSE_UNAUTHORIZED, $conn->closedWith);
        $this->assertSame('system.error', json_decode($conn->sent[0], true)['event']);
        $this->assertSame(['admin' => 0, 'public' => 0], $this->hub->counts());
    }

    public function testRejectsSocketWithNoTokenParameter(): void
    {
        $conn = new FakeConnection(new Request('GET', 'ws://relay/'));
        $this->hub->onOpen($conn);
        $this->assertSame(RelayHub::CLOSE_UNAUTHORIZED, $conn->closedWith);
    }

    public function testGreetsAuthorisedSocketWithScope(): void
    {
        $conn = $this->connect('admin');
        $hello = json_decode($conn->sent[0], true);
        $this->assertSame('system.hello', $hello['event']);
        $this->assertSame('admin', $hello['data']['scope']);
        $this->assertNull($conn->closedWith);
    }

    public function testAdminReceivesFullPayloadWithRelayMetadata(): void
    {
        $admin = $this->connect('admin');
        $delivered = $this->hub->relay($this->raw(CommentEvent::CREATED, $this->adminComment()));

        $this->assertSame(1, $delivered);
        $frame = json_decode(end($admin->sent), true);
        $this->assertSame(CommentEvent::CREATED, $frame['event']);
        $this->assertSame('10.9.9.9', $frame['data']['ip']);
        $this->assertSame('abcdef0123456789', $frame['trace']);
        $this->assertSame(['relayed_at' => 1700000000.5, 'recipients' => 1], $frame['relay']);
    }

    public function testPublicSocketNeverSeesPrivateFields(): void
    {
        $public = $this->connect('public');
        $this->hub->relay($this->raw(CommentEvent::CREATED, $this->adminComment()));

        $data = json_decode(end($public->sent), true)['data'];
        $this->assertSame(RelayHub::PUBLIC_FIELDS, array_keys($data));
        $this->assertArrayNotHasKey('ip', $data);
        $this->assertArrayNotHasKey('status', $data);
    }

    public function testHiddenCommentsAreInvisibleToPublicAndHidingIsAnnouncedAsDeletion(): void
    {
        $admin = $this->connect('admin');
        $public = $this->connect('public');
        $before = count($public->sent);

        $this->assertSame(1, $this->hub->relay($this->raw(CommentEvent::CREATED, $this->adminComment(['status' => 0, 'status_label' => 'hidden']))));
        $this->assertCount($before, $public->sent, 'a hidden comment must not reach public sockets');

        $this->hub->relay($this->raw(CommentEvent::UPDATED, $this->adminComment(['status' => 0, 'status_label' => 'hidden'])));
        $frame = json_decode(end($public->sent), true);
        $this->assertSame(CommentEvent::DELETED, $frame['event']);
        $this->assertSame(['id' => 11, 'is_demo' => false], $frame['data']);
        $this->assertSame(CommentEvent::UPDATED, json_decode(end($admin->sent), true)['event']);
    }

    public function testFanOutCountsEverySocketAndSkipsClosedOnes(): void
    {
        $a = $this->connect('admin');
        $b = $this->connect('admin');
        $p = $this->connect('public');
        $this->hub->onClose($b);

        $this->assertSame(2, $this->hub->relay($this->raw(CommentEvent::CREATED, $this->adminComment())));
        $this->assertSame(['admin' => 1, 'public' => 1], $this->hub->counts());
        $this->assertSame(2, json_decode(end($a->sent), true)['relay']['recipients']);
        $this->assertCount(1, $b->sent, 'closed socket only got its hello');
    }

    public function testMalformedRedisMessageIsDroppedAndLogged(): void
    {
        $admin = $this->connect('admin');
        $this->assertSame(0, $this->hub->relay('{"v":1,"event":"evil"}'));
        $this->assertCount(1, $admin->sent);
        $this->assertSame('warning', end($this->logs)[0]);
    }

    public function testPingIsAnsweredOnlyForAuthorisedSockets(): void
    {
        $admin = $this->connect('admin');
        $this->hub->onMessage($admin, 'ping');
        $this->assertSame('system.pong', json_decode(end($admin->sent), true)['event']);

        $stranger = new FakeConnection(new Request('GET', 'ws://relay/'));
        $this->hub->onMessage($stranger, 'ping');
        $this->assertSame([], $stranger->sent);
    }

    public function testPurgeReachesPublicSocketsWithIdsOnly(): void
    {
        $public = $this->connect('public');
        $this->hub->relay($this->raw(CommentEvent::PURGED, ['ids' => [1, 2, 3], 'is_demo' => true]));
        $this->assertSame(['ids' => [1, 2, 3], 'is_demo' => true], json_decode(end($public->sent), true)['data']);
    }
}

final class FakeConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $sent = [];
    public ?int $closedWith = null;
    private static int $seq = 0;
    public int $resourceId;

    public function __construct(public ?Request $httpRequest)
    {
        $this->resourceId = ++self::$seq;
    }

    public function send($data)
    {
        $this->sent[] = $data;

        return $this;
    }

    public function close($code = 1000)
    {
        $this->closedWith = $code;
    }
}
