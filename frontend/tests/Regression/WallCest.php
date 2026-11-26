<?php

declare(strict_types=1);

namespace frontend\tests\Regression;

use common\fixtures\CommentFixture;
use common\models\Comment;
use common\models\CommentLike;
use common\realtime\CommentEvent;
use common\realtime\SocketTokenService;
use common\tests\Support\Realtime\SpyCommentEventPublisher;
use frontend\tests\Support\RegressionTester;
use Yii;

/**
 * Public wall, in-process (real routing/controllers/MySQL, spy publisher).
 * Each test names the pipeline step it locks via @group step-Sx.
 */
final class WallCest
{
    public function _fixtures(): array
    {
        return ['comments' => ['class' => CommentFixture::class, 'dataFile' => codecept_data_dir() . '../../../../common/tests/Support/data/comment.php']];
    }

    public function _before(RegressionTester $I): void
    {
        SpyCommentEventPublisher::reset();
    }

    private function csrf(RegressionTester $I): string
    {
        $I->amOnPage('/');

        return $I->grabAttributeFrom('meta[name="csrf-token"]', 'content');
    }

    /** @return array<string, mixed> */
    private function post(RegressionTester $I, array $comment, array $extra = []): array
    {
        $I->sendAjaxPostRequest('/comments', ['_csrf-frontend' => $this->csrf($I), 'Comment' => $comment] + $extra);

        return json_decode($I->grabPageSource(), true) ?? [];
    }

    /** @group step-S1 */
    public function wallRendersComposerWithoutLogin(RegressionTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->seeElement('form[data-composer-form] textarea[name="Comment[body]"]');
        $I->seeElement('input[name="Comment[author_name]"]');
        $I->seeElement('meta[name="csrf-token"]');
        $I->dontSeeElement('input[type="password"]');
        $I->seeElement('a[href="/game/"]');
    }

    /** @group step-S4 */
    public function feedIsNewestFirstWithIdTieBreakAndHidesHidden(RegressionTester $I): void
    {
        $I->amOnPage('/');
        $I->assertSame(
            ['Simulated traffic', 'Same second B', 'Same second A', 'First!'],
            $I->grabMultiple('[data-feed] .post-body'),
        );
        $I->dontSee('spam spam');
        $I->see('4', '[data-total]');
    }

    /**
     * @group step-S2
     * @group step-S3
     * @group step-S5
     */
    public function ajaxPostPersistsAndPublishesOnce(RegressionTester $I): void
    {
        $json = $this->post($I, ['author_name' => 'Регрессия', 'body' => 'hello wall']);

        $I->seeResponseCodeIs(201);
        $I->assertSame('hello wall', $json['comment']['body']);
        $I->assertSame('regressiya', $json['comment']['handle']);
        $I->assertArrayNotHasKey('ip', $json['comment'], 'public response must not echo the IP');
        $I->assertTrue($json['realtime']['delivered']);
        $I->seeRecord(Comment::class, ['id' => $json['comment']['id'], 'status' => Comment::STATUS_PUBLISHED, 'is_demo' => 0]);

        $events = SpyCommentEventPublisher::published(CommentEvent::CREATED);
        $I->assertCount(1, $events);
        $I->assertSame($json['comment']['id'], $events[0]->data['id']);
        $I->assertSame($json['realtime']['trace'], $events[0]->trace);
    }

    /** @group step-S2 */
    public function invalidPostIsRejectedWith422AndNothingHappens(RegressionTester $I): void
    {
        $before = (int) Comment::find()->count();
        $json = $this->post($I, ['author_name' => 'Ada', 'body' => "   \n  "]);

        $I->seeResponseCodeIs(422);
        $I->assertArrayHasKey('body', $json['errors']);
        $I->assertSame($before, (int) Comment::find()->count());
        $I->assertSame([], SpyCommentEventPublisher::published());
    }

    /** @group step-S2 */
    public function blankNameGetsAStableGuestHandle(RegressionTester $I): void
    {
        $first = $this->post($I, ['author_name' => '', 'body' => 'one']);
        $second = $this->post($I, ['author_name' => '', 'body' => 'two']);

        $I->assertMatchesRegularExpression('/^guest_[a-f0-9]{4}$/', $first['comment']['author_name']);
        $I->assertSame($first['comment']['author_name'], $second['comment']['author_name'], 'same visitor session → same guest');
    }

    /** @group step-S2 */
    public function privilegedFieldsCannotBeInjected(RegressionTester $I): void
    {
        $json = $this->post($I, ['author_name' => 'Eve', 'body' => 'x', 'status' => 0, 'likes' => 500, 'is_demo' => 1, 'ip' => '6.6.6.6']);
        $I->seeRecord(Comment::class, ['id' => $json['comment']['id'], 'status' => 10, 'likes' => 0, 'is_demo' => 0]);
        $I->dontSeeRecord(Comment::class, ['ip' => '6.6.6.6']);
    }

    /** @group step-S2 */
    public function requestWithoutCsrfTokenIsRefused(RegressionTester $I): void
    {
        $I->sendAjaxPostRequest('/comments', ['Comment' => ['author_name' => 'Ada', 'body' => 'no token']]);
        $I->seeResponseCodeIs(400);
        $I->dontSeeRecord(Comment::class, ['body' => 'no token']);
    }

    /** @group step-S2 */
    public function wrongVerbsAreRejected(RegressionTester $I): void
    {
        $I->sendAjaxRequest('PUT', '/comments', []);
        $I->seeResponseCodeIs(405);
        $I->sendAjaxRequest('DELETE', '/comments/1/like', []);
        $I->seeResponseCodeIs(405);
    }

    /**
     * @group step-S2
     * @group step-S4
     */
    public function noJavascriptFallbackPostsAndRedirects(RegressionTester $I): void
    {
        $I->amOnPage('/');
        $I->submitForm('form[data-composer-form]', ['Comment[author_name]' => 'NoJS', 'Comment[body]' => 'plain html form']);
        $I->seeResponseCodeIs(200);
        $I->seeCurrentUrlEquals('/index-test.php');
        $I->see('Опубликовано.');
        $I->see('plain html form', '.post-body');

        $I->submitForm('form[data-composer-form]', ['Comment[author_name]' => 'NoJS', 'Comment[body]' => '']);
        $I->seeResponseCodeIs(422);
        $I->seeElement('.has-error [name="Comment[body]"]');
    }

    /** @group step-S4 */
    public function markupInCommentsIsRenderedAsText(RegressionTester $I): void
    {
        $this->post($I, ['author_name' => '<b>Bold</b>', 'body' => '<script>alert("xss")</script>']);
        $I->amOnPage('/');
        $source = $I->grabPageSource();
        $I->assertStringContainsString('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $source);
        $I->assertStringNotContainsString('<script>alert("xss")</script>', $source);
        $I->assertStringNotContainsString('<b>Bold</b>', $source);
    }

    /**
     * Keyset paging: new posts arriving at the top must not shift page 2
     * (OFFSET paging would repeat rows here).
     *
     * @group step-S4
     */
    public function loadMoreNeverDuplicatesWhileNewCommentsArrive(RegressionTester $I): void
    {
        for ($i = 1; $i <= 20; $i++) {
            // Raw insert: TimestampBehavior would overwrite the fixed timeline.
            Yii::$app->db->createCommand()->insert('{{%comment}}', [
                'author_name' => 'Bulk', 'body' => "bulk {$i}", 'status' => 10, 'is_demo' => 0, 'likes' => 0,
                'created_at' => 1700001000 + $i, 'updated_at' => 1700001000 + $i,
            ])->execute();
        }
        $I->amOnPage('/');
        $page1 = $I->grabMultiple('[data-feed] .post-body');
        $cursor = $I->grabAttributeFrom('[data-more]', 'data-cursor');
        $I->assertCount(15, $page1);

        // Three new comments land while the reader is on page 1.
        foreach (['late 1', 'late 2', 'late 3'] as $body) {
            $this->post($I, ['author_name' => 'Late', 'body' => $body]);
        }

        $I->sendAjaxGetRequest('/comments', ['before' => $cursor]);
        $page2 = json_decode($I->grabPageSource(), true);
        preg_match_all('#data-slot="body">([^<]*)<#', $page2['html'], $m);

        $I->assertSame([], array_values(array_intersect($page1, $m[1])), 'no row may appear on both pages');
        $I->assertSame(['bulk 5', 'bulk 4', 'bulk 3', 'bulk 2', 'bulk 1', 'Simulated traffic', 'Same second B', 'Same second A', 'First!'], $m[1]);
        $I->assertNull($page2['next_cursor']);
    }

    /** @group step-S4 */
    public function malformedCursorIs400(RegressionTester $I): void
    {
        $I->sendAjaxGetRequest('/comments', ['before' => "1' OR 1=1"]);
        $I->seeResponseCodeIs(400);
    }

    /** @group step-S4 */
    public function likeTogglesPersistsAndPublishes(RegressionTester $I): void
    {
        $this->csrf($I);
        $token = $I->grabAttributeFrom('meta[name="csrf-token"]', 'content');

        $I->sendAjaxPostRequest('/comments/1/like', ['_csrf-frontend' => $token]);
        $I->assertSame(['id' => 1, 'liked' => true, 'likes' => 1], array_slice(json_decode($I->grabPageSource(), true), 0, 3));
        $I->seeRecord(Comment::class, ['id' => 1, 'likes' => 1]);
        $I->assertSame(CommentEvent::LIKED, SpyCommentEventPublisher::last()->event);

        $I->sendAjaxPostRequest('/comments/1/like', ['_csrf-frontend' => $token]);
        $I->assertFalse(json_decode($I->grabPageSource(), true)['liked']);
        $I->seeRecord(Comment::class, ['id' => 1, 'likes' => 0]);

        $I->amOnPage('/');
        $I->dontSeeElement('[data-comment-id="1"] .like.is-liked');
    }

    /** @group step-S4 */
    public function hiddenCommentsCannotBeLiked(RegressionTester $I): void
    {
        $token = $this->csrf($I);
        $I->sendAjaxPostRequest('/comments/2/like', ['_csrf-frontend' => $token]);
        $I->seeResponseCodeIs(404);
        $I->assertSame(0, (int) CommentLike::find()->count());
    }

    /** @group step-S6 */
    public function publicSocketTokenHasPublicScopeOnly(RegressionTester $I): void
    {
        $I->sendAjaxGetRequest('/ws-token');
        $json = json_decode($I->grabPageSource(), true);
        $grant = Yii::$container->get(SocketTokenService::class)->verify($json['token']);
        $I->assertSame(SocketTokenService::SCOPE_PUBLIC, $grant->scope);
        $I->assertSame(Yii::$app->params['ws.publicUrl'], $json['url']);
        $I->assertSame('no-store', Yii::$app->response->headers->get('Cache-Control'));
    }

    /** @group step-S4 */
    public function avatarsAreDeterministicAndCacheable(RegressionTester $I): void
    {
        $I->amOnPage('/avatar.svg?name=' . urlencode('Ада'));
        $I->assertSame('image/svg+xml; charset=utf-8', Yii::$app->response->headers->get('Content-Type'));
        $etag = Yii::$app->response->headers->get('ETag');
        $svg = $I->grabPageSource();

        $I->amOnPage('/avatar.svg?name=ada');
        $I->assertSame($svg, $I->grabPageSource(), 'name and its handle share one avatar');

        $I->haveHttpHeader('If-None-Match', $etag);
        $I->amOnPage('/avatar.svg?name=ada');
        $I->seeResponseCodeIs(304);
    }

    /**
     * Redis down must be visible, never faked — and must not lose the comment.
     *
     * @group step-S5
     */
    public function relayOutageIsReportedButCommentIsKept(RegressionTester $I): void
    {
        SpyCommentEventPublisher::failNextPublishes();
        $json = $this->post($I, ['author_name' => 'Ada', 'body' => 'during outage']);

        $I->seeResponseCodeIs(201);
        $I->assertFalse($json['realtime']['delivered']);
        $I->assertSame('realtime relay unavailable', $json['realtime']['error']);
        $I->seeRecord(Comment::class, ['body' => 'during outage']);
    }
}
