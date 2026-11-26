<?php

declare(strict_types=1);

namespace common\tests\Unit\Services;

use Codeception\Test\Unit;
use common\fixtures\CommentFixture;
use common\models\Comment;
use common\models\CommentLike;
use common\realtime\CommentEvent;
use common\services\CommentService;
use common\services\LikeRateLimitException;
use common\tests\Support\Realtime\SpyCommentEventPublisher;

/**
 * Persist-then-publish business rules, against real MySQL.
 *
 * @group step-S3
 * @group step-S5
 */
final class CommentServiceTest extends Unit
{
    private CommentService $service;

    public function _fixtures(): array
    {
        return ['comments' => ['class' => CommentFixture::class, 'dataFile' => codecept_data_dir() . 'comment.php']];
    }

    protected function _before(): void
    {
        SpyCommentEventPublisher::reset();
        $this->service = new CommentService(new SpyCommentEventPublisher());
    }

    public function testCreatePersistsThenPublishesOnce(): void
    {
        $out = $this->service->create(['author_name' => 'Ada', 'body' => 'Hello'], '10.0.0.9', false, 'guest_0000');

        $this->assertTrue($out->isSaved());
        $row = Comment::findOne($out->comment->id);
        $this->assertSame('Hello', $row->body);
        $this->assertSame('10.0.0.9', $row->ip);
        $this->assertSame(Comment::STATUS_PUBLISHED, (int) $row->status);
        $this->assertSame(0, (int) $row->is_demo);

        $events = SpyCommentEventPublisher::published(CommentEvent::CREATED);
        $this->assertCount(1, $events);
        $this->assertSame($row->toAdminArray(), $events[0]->data, 'the event carries exactly the committed row');
        $this->assertSame($events[0]->trace, $out->realtime->trace);
    }

    /**
     * @group step-S2
     */
    public function testBlankNameFallsBackToTheSessionGuestName(): void
    {
        $out = $this->service->create(['author_name' => '   ', 'body' => 'anon'], null, false, 'guest_ab12');
        $this->assertSame('guest_ab12', $out->comment->author_name);
        $this->assertSame('guest_ab12', $out->comment->getHandle());
    }

    /**
     * @group step-S2
     */
    public function testInvalidCommentIsNeitherSavedNorPublished(): void
    {
        $before = Comment::find()->count();
        $out = $this->service->create(['author_name' => 'Ada', 'body' => ''], null, false, 'guest_0000');

        $this->assertFalse($out->isSaved());
        $this->assertTrue($out->comment->hasErrors('body'));
        $this->assertSame($before, Comment::find()->count());
        $this->assertSame([], SpyCommentEventPublisher::published());
    }

    public function testRelayOutageNeverLosesTheComment(): void
    {
        SpyCommentEventPublisher::failNextPublishes();
        $out = $this->service->create(['author_name' => 'Ada', 'body' => 'still saved'], null, false, 'guest_0000');

        $this->assertTrue($out->isSaved());
        $this->assertFalse($out->realtime->delivered);
        $this->assertNotNull(Comment::findOne(['body' => 'still saved']));
    }

    /**
     * @group step-S9
     */
    public function testUpdatePublishesTheNewState(): void
    {
        $c = Comment::findOne(1);
        $result = $this->service->update($c, ['body' => 'edited', 'status' => '0']);

        $this->assertNotNull($result);
        $event = SpyCommentEventPublisher::last();
        $this->assertSame(CommentEvent::UPDATED, $event->event);
        $this->assertSame('edited', $event->data['body']);
        $this->assertSame(Comment::STATUS_HIDDEN, $event->data['status']);
    }

    /**
     * @group step-S9
     */
    public function testDeletePublishesIdAfterRemoval(): void
    {
        $this->service->delete(Comment::findOne(3));
        $this->assertNull(Comment::findOne(3));
        $this->assertSame(['id' => 3, 'is_demo' => false, 'status' => 10], SpyCommentEventPublisher::last()->data);
    }

    public function testLikeTogglesAndIsUniquePerVoter(): void
    {
        $c = Comment::findOne(1);
        $this->assertTrue($this->service->toggleLike($c, 'voter-a', '10.0.0.1', 30)->liked);
        $this->assertSame(1, (int) Comment::findOne(1)->likes);
        $this->assertTrue($this->service->hasLiked($c, 'voter-a'));

        $second = $this->service->toggleLike($c, 'voter-a', '10.0.0.1', 30);
        $this->assertFalse($second->liked, 'second click by the same voter is an unlike');
        $this->assertSame(0, $second->likes);

        $this->service->toggleLike($c, 'voter-b', '10.0.0.1', 30);
        $this->service->toggleLike($c, 'voter-c', '10.0.0.2', 30);
        $this->assertSame(2, (int) Comment::findOne(1)->likes);
        $this->assertSame(CommentEvent::LIKED, SpyCommentEventPublisher::last()->event);
    }

    public function testLikesAreRateLimitedPerIp(): void
    {
        $this->service->toggleLike(Comment::findOne(1), 'v1', '10.6.6.6', 2);
        $this->service->toggleLike(Comment::findOne(3), 'v2', '10.6.6.6', 2);

        $this->expectException(LikeRateLimitException::class);
        $this->service->toggleLike(Comment::findOne(4), 'v3', '10.6.6.6', 2);
    }

    public function testLikeCounterNeverGoesNegative(): void
    {
        $c = Comment::findOne(4); // likes = 0 in fixture
        CommentLike::getDb()->createCommand()->insert('comment_like', [
            'comment_id' => 4, 'voter' => hash('sha256', 'voter|ghost'), 'ip_hash' => str_repeat('0', 64), 'created_at' => time(),
        ])->execute();

        $this->assertSame(0, $this->service->toggleLike($c, 'ghost', '10.0.0.1', 30)->likes);
    }

    public function testPurgeRemovesOnlyDemoComments(): void
    {
        $result = $this->service->purgeDemo();

        $this->assertSame(1, $result['deleted']);
        $this->assertSame([5], $result['ids']);
        $this->assertSame(0, (int) Comment::find()->demo()->count());
        $this->assertSame(4, (int) Comment::find()->count(), 'real comments are untouched');
        $this->assertSame(CommentEvent::PURGED, SpyCommentEventPublisher::last()->event);
    }
}
