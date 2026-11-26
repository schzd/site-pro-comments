<?php

declare(strict_types=1);

namespace frontend\tests\Regression;

use common\fixtures\CommentFixture;
use common\models\Comment;
use common\realtime\CommentEvent;
use common\tests\Support\Realtime\SpyCommentEventPublisher;
use frontend\tests\Support\RegressionTester;

/**
 * /demo must drive the real create endpoint and clean up after itself.
 *
 * @group demo
 */
final class DemoCest
{
    public function _fixtures(): array
    {
        return ['comments' => ['class' => CommentFixture::class, 'dataFile' => codecept_data_dir() . '../../../../common/tests/Support/data/comment.php']];
    }

    public function _before(RegressionTester $I): void
    {
        SpyCommentEventPublisher::reset();
    }

    public function demoPageRendersPipelineAndControls(RegressionTester $I): void
    {
        $I->amOnPage('/demo');
        $I->seeResponseCodeIs(200);
        foreach (['visitor', 'frontend', 'mysql', 'redis', 'ws', 'admin'] as $node) {
            $I->seeElement('#node-' . $node);
        }
        $I->seeElement('[data-play-big]');
        $I->seeElement('[data-reset]');
        $I->seeElement('input[type=range][data-speed]');
        $I->seeElement('template#card-template article.post'); // same card component as the wall
        $personas = json_decode($I->grabAttributeFrom('[data-demo]', 'data-personas'), true);
        $I->assertGreaterThanOrEqual(5, count($personas));
    }

    /** @group step-S3 */
    public function demoFlagMarksCommentsAsDemo(RegressionTester $I): void
    {
        $I->amOnPage('/demo');
        $token = $I->grabAttributeFrom('meta[name="csrf-token"]', 'content');
        $I->sendAjaxPostRequest('/comments', ['_csrf-frontend' => $token, 'demo' => '1', 'Comment' => ['author_name' => 'Demo Anna', 'body' => 'auto']]);

        $I->seeResponseCodeIs(201);
        $json = json_decode($I->grabPageSource(), true);
        $I->assertTrue($json['comment']['is_demo']);
        $I->seeRecord(Comment::class, ['id' => $json['comment']['id'], 'is_demo' => 1]);
    }

    public function resetPurgesOnlyDemoCommentsAndAnnouncesIt(RegressionTester $I): void
    {
        $I->amOnPage('/demo');
        $token = $I->grabAttributeFrom('meta[name="csrf-token"]', 'content');
        $I->sendAjaxPostRequest('/demo/reset', ['_csrf-frontend' => $token]);

        $I->seeResponseCodeIs(200);
        $json = json_decode($I->grabPageSource(), true);
        $I->assertSame(1, $json['deleted']);
        $I->assertSame([5], $json['ids']);
        $I->assertSame(0, (int) Comment::find()->demo()->count());
        $I->assertSame(4, (int) Comment::find()->count());
        $I->assertSame(CommentEvent::PURGED, SpyCommentEventPublisher::last()->event);
    }

    public function resetRequiresPost(RegressionTester $I): void
    {
        $I->amOnPage('/demo/reset');
        $I->seeResponseCodeIs(405);
        $I->assertSame(1, (int) Comment::find()->demo()->count());
    }
}
