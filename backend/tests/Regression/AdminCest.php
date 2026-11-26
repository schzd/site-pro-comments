<?php

declare(strict_types=1);

namespace backend\tests\Regression;

use backend\tests\Support\RegressionTester;
use common\fixtures\CommentFixture;
use common\fixtures\UserFixture;
use common\models\Comment;
use common\realtime\CommentEvent;
use common\realtime\SocketTokenService;
use common\tests\Support\Realtime\SpyCommentEventPublisher;
use Yii;

/**
 * Admin panel, in-process: access control, list, edit, hide, delete.
 */
final class AdminCest
{
    public function _fixtures(): array
    {
        return [
            'user' => ['class' => UserFixture::class, 'dataFile' => codecept_data_dir() . 'login_data.php'],
            'comments' => ['class' => CommentFixture::class, 'dataFile' => codecept_data_dir() . '../../../../common/tests/Support/data/comment.php'],
        ];
    }

    public function _before(RegressionTester $I): void
    {
        SpyCommentEventPublisher::reset();
    }

    /** Log in through the real form (session cookie carries over between requests). */
    private function login(RegressionTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('#login-form', ['LoginForm[username]' => 'erau', 'LoginForm[password]' => 'password_0']);
        $I->seeElement('[data-admin-live]');
    }

    private function csrf(RegressionTester $I): string
    {
        return $I->grabAttributeFrom('meta[name="csrf-token"]', 'content');
    }

    /** @group step-S7 */
    public function guestsAreSentToLogin(RegressionTester $I): void
    {
        foreach (['/', '/comments/1/edit', '/ws-token', '/?filter=hidden'] as $url) {
            $I->amOnPage($url);
            $I->seeElement('#login-form');
        }
    }

    /** @group step-S7 */
    public function wrongPasswordIsRejected(RegressionTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('#login-form', ['LoginForm[username]' => 'erau', 'LoginForm[password]' => 'nope']);
        $I->see('Incorrect username or password.');
        $I->dontSeeElement('[data-admin-live]');
    }

    /**
     * @group step-S7
     * @group step-S8
     */
    public function adminLogsInAndSeesTheLiveList(RegressionTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('#login-form', ['LoginForm[username]' => 'erau', 'LoginForm[password]' => 'password_0']);
        $I->seeElement('[data-admin-live]');
        $I->see('@erau', '.cp-user');
        $I->seeElement('template#row-template tr.cp-row');
        $I->seeElement('[data-live]');
    }

    /** @group step-S8 */
    public function listIsNewestFirstAndIncludesHiddenAndDemoRows(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/');
        $I->assertSame(['#5', '#4', '#3', '#2', '#1'], $I->grabMultiple('[data-rows] .cp-id'));
        $I->see('10.0.0.2', '[data-comment-id="2"] .cp-ip');
        $I->see('hidden', '[data-comment-id="2"] [data-slot="status"]');
        $I->dontSeeElement('[data-comment-id="4"] [data-slot="demo"]:not([hidden])');
        $I->seeElement('[data-comment-id="5"] [data-slot="demo"]:not([hidden])');
        $I->see('5', '[data-count="all"]');
    }

    /** @group step-S8 */
    public function filtersNarrowTheList(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/?filter=hidden');
        $I->assertSame(['#2'], $I->grabMultiple('[data-rows] .cp-id'));
        $I->amOnPage('/?filter=demo');
        $I->assertSame(['#5'], $I->grabMultiple('[data-rows] .cp-id'));
        $I->amOnPage('/?filter=nonsense');
        $I->assertCount(5, $I->grabMultiple('[data-rows] .cp-id'));
    }

    /** @group step-S8 */
    public function adminViewEscapesMarkup(RegressionTester $I): void
    {
        Yii::$app->db->createCommand()->insert('{{%comment}}', ['author_name' => 'x', 'body' => '<img src=x onerror=alert(1)>', 'status' => 10, 'is_demo' => 0, 'likes' => 0, 'created_at' => 1800000000, 'updated_at' => 1800000000])->execute();
        $this->login($I);
        $I->amOnPage('/');
        $I->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $I->grabPageSource());
    }

    /** @group step-S7 */
    public function adminSocketTokenCarriesAdminScopeAndUserId(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/ws-token');
        $grant = Yii::$container->get(SocketTokenService::class)->verify(json_decode($I->grabPageSource(), true)['token']);
        $I->assertSame(SocketTokenService::SCOPE_ADMIN, $grant->scope);
        $I->assertSame(1, $grant->subject);
    }

    /** @group step-S9 */
    public function editSavesAndPublishesUpdate(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/comments/1/edit');
        $I->submitForm('#comment-form', ['Comment[author_name]' => 'Ada L.', 'Comment[body]' => 'Edited by admin', 'Comment[status]' => '10']);

        $I->see('Comment #1 saved.');
        $I->seeRecord(Comment::class, ['id' => 1, 'body' => 'Edited by admin', 'author_name' => 'Ada L.']);
        $event = SpyCommentEventPublisher::last();
        $I->assertSame(CommentEvent::UPDATED, $event->event);
        $I->assertSame('Edited by admin', $event->data['body']);
    }

    /** @group step-S9 */
    public function hidingAPublishedCommentIsPublishedWithHiddenStatus(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/comments/4/edit');
        $I->submitForm('#comment-form', ['Comment[status]' => '0']);

        $I->seeRecord(Comment::class, ['id' => 4, 'status' => 0]);
        $I->assertSame(Comment::STATUS_HIDDEN, SpyCommentEventPublisher::last()->data['status']);
    }

    /** @group step-S9 */
    public function invalidEditShowsErrorsAndPublishesNothing(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/comments/1/edit');
        $I->submitForm('#comment-form', ['Comment[body]' => '']);

        $I->seeResponseCodeIs(422);
        $I->seeElement('.has-error #comment-body');
        $I->seeRecord(Comment::class, ['id' => 1, 'body' => 'First!']);
        $I->assertSame([], SpyCommentEventPublisher::published());
    }

    /** @group step-S9 */
    public function deleteRemovesRowAndPublishesDeletion(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/');
        $I->sendAjaxPostRequest('/comments/3/delete', ['_csrf-backend' => $this->csrf($I)]);

        $I->dontSeeRecord(Comment::class, ['id' => 3]);
        $I->assertSame(['id' => 3, 'is_demo' => false, 'status' => 10], SpyCommentEventPublisher::last()->data);
    }

    /** @group step-S9 */
    public function deleteIsPostOnlyCsrfProtectedAnd404ForUnknownIds(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/comments/1/delete');
        $I->seeResponseCodeIs(405);

        $I->amOnPage('/');
        $I->sendAjaxPostRequest('/comments/1/delete', []);
        $I->seeResponseCodeIs(400);
        $I->seeRecord(Comment::class, ['id' => 1]);

        $I->amOnPage('/');
        $I->sendAjaxPostRequest('/comments/999/delete', ['_csrf-backend' => $this->csrf($I)]);
        $I->seeResponseCodeIs(404);
    }

    /** @group step-S7 */
    public function logoutIsPostOnly(RegressionTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/logout');
        $I->seeResponseCodeIs(405);
    }
}
