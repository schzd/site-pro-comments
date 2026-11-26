<?php

declare(strict_types=1);

namespace blackbox\tests\Smoke;

use blackbox\tests\Support\SmokeTester;

/**
 * SMOKE: the deployed stack answers on every critical path, over real HTTP and
 * a real WebSocket. No browser, no in-process Yii — seconds per run.
 *
 * @group smoke
 */
final class PipelineSmokeCest
{
    /** @group step-S1 */
    public function wallIsUpAndOffersTheComposer(SmokeTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->seeElement('textarea[name="Comment[body]"]');
        $I->seeElement('meta[name="csrf-token"]');
        $I->seeElement('[data-token-url]');
    }

    /**
     * @group step-S2
     * @group step-S3
     * @group step-S5
     */
    public function postIsAcceptedAndReachesTheRelay(SmokeTester $I): void
    {
        $text = $I->uniqueText('smoke post');
        $res = $I->postComment('Smoke Bot', $text);

        $I->assertSame(201, $res['status'], json_encode($res['body']));
        $I->assertSame($text, $res['body']['comment']['body']);
        $I->assertTrue($res['body']['realtime']['delivered'], 'Redis PUBLISH failed');
        $I->assertGreaterThanOrEqual(1, $res['body']['realtime']['subscribers'], 'Redis is up but no WS relay is subscribed');
    }

    /** @group step-S4 */
    public function postedCommentIsInThePublicFeed(SmokeTester $I): void
    {
        $text = $I->uniqueText('smoke feed');
        $I->postComment('Smoke Bot', $text);
        $I->amOnPage('/');
        $I->see($text, '[data-feed] .post-body');
    }

    /** @group step-S2 */
    public function postWithoutCsrfIsRefused(SmokeTester $I): void
    {
        $I->haveHttpHeader('X-Requested-With', 'XMLHttpRequest');
        $I->sendAjaxPostRequest('/comments', ['Comment' => ['author_name' => 'x', 'body' => 'no csrf']]);
        $I->seeResponseCodeIs(400);
    }

    /** @group step-S6 */
    public function relayRejectsSocketsWithoutValidToken(SmokeTester $I): void
    {
        $ws = $I->openSocketRaw('/?token=forged.token');
        $I->assertSame('system.error', $ws->receiveJson()['event'] ?? null);
        $ws->receiveJson(2);
        $I->assertSame(4401, $ws->closeCode);
        $ws->close();
    }

    /** @group step-S6 */
    public function relayGreetsPublicSocket(SmokeTester $I): void
    {
        $ws = $I->openSocket($I->publicSocketToken());
        $hello = $ws->receiveJson();
        $I->assertSame('system.hello', $hello['event']);
        $I->assertSame('public', $hello['data']['scope']);
        $ws->close();
    }

    /**
     * Browsers never hit the relay port directly: each app tunnels /ws/ to it
     * on its own origin (works behind HTTPS / Codespaces port forwarding).
     *
     * @group step-S6
     */
    public function relayIsReachableSameOriginThroughBothApps(SmokeTester $I): void
    {
        $public = $I->openSocketThroughApp($I->frontendUrl(), $I->publicSocketToken());
        $I->assertSame('public', $public->receiveJson()['data']['scope'] ?? null);
        $public->close();

        $admin = $I->openSocketThroughApp($I->backendUrl(), $I->adminSocketToken());
        $I->assertSame('admin', $admin->receiveJson()['data']['scope'] ?? null);
        $admin->close();
    }

    /** @group step-S7 */
    public function adminLoginPageIsUp(SmokeTester $I): void
    {
        $I->amOnUrl($I->backendUrl('/login'));
        $I->seeResponseCodeIs(200);
        $I->seeElement('#login-form');
    }

    /**
     * The whole pipeline, minus the browser: an admin socket is open, a visitor
     * posts, the frame arrives with the private fields and the same trace id.
     *
     * @group step-S8
     * @group step-S6
     */
    public function adminSocketReceivesTheNewCommentLive(SmokeTester $I): void
    {
        $ws = $I->openSocket($I->adminSocketToken());
        $I->assertSame('admin', $ws->receiveJson()['data']['scope'] ?? null);

        $text = $I->uniqueText('smoke live');
        $res = $I->postComment('Smoke Bot', $text);
        $trace = $res['body']['realtime']['trace'];

        $frame = $ws->waitFor(static fn (array $f) => ($f['trace'] ?? null) === $trace, 8);
        $ws->close();

        $I->assertNotNull($frame, 'no WebSocket frame for trace ' . $trace);
        $I->assertSame('comment.created', $frame['event']);
        $I->assertSame($text, $frame['data']['body']);
        $I->assertArrayHasKey('ip', $frame['data'], 'admin scope carries private fields');
        $I->assertGreaterThanOrEqual(1, $frame['relay']['recipients']);
        $I->assertGreaterThanOrEqual($frame['published_at'], $frame['relay']['relayed_at']);
    }

    /** @group step-S8 */
    public function adminListShowsTheComment(SmokeTester $I): void
    {
        $text = $I->uniqueText('smoke admin');
        $I->postComment('Smoke Bot', $text);

        $creds = $I->adminCredentials();
        $I->amOnUrl($I->backendUrl('/login'));
        $I->submitForm('#login-form', ['LoginForm[username]' => $creds['username'], 'LoginForm[password]' => $creds['password']]);
        $I->seeElement('[data-admin-live]');
        $I->see($text, '[data-rows]');
    }

    public function demoPageIsUp(SmokeTester $I): void
    {
        $I->amOnPage('/demo');
        $I->seeResponseCodeIs(200);
        $I->seeElement('[data-play-big]');
    }

    /** @group step-S6 */
    public function relayClosesTokenlessSocketWith4401(SmokeTester $I): void
    {
        $ws = $I->openSocketRaw('/');
        $I->assertSame('unauthorized', $ws->receiveJson()['data']['reason'] ?? null);
        $ws->receiveJson(2);
        $I->assertSame(4401, $ws->closeCode);
        $ws->close();
    }
}
