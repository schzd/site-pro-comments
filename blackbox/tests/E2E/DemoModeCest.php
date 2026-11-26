<?php

declare(strict_types=1);

namespace blackbox\tests\E2E;

use blackbox\tests\Support\E2ETester;

/**
 * /demo drives the REAL pipeline: its auto-poster's POSTs land in MySQL, its
 * admin pane fills from real WebSocket frames, the real admin panel shows the
 * rows, and Reset really deletes them.
 *
 * @group e2e
 * @group demo
 * @group step-S5
 * @group step-S6
 */
final class DemoModeCest
{
    public function demoPostsRealCommentsAndResetCleansUp(E2ETester $I): void
    {
        $I->amOnUrl($I->frontendUrl('/demo'));
        $I->waitForElement('[data-live][data-state="live"]', 10);

        // Fastest interval, then play.
        $I->executeJS('const s = document.querySelector("[data-speed]"); s.value = 600; s.dispatchEvent(new Event("input"));');
        $I->click('[data-play-big]');
        $I->waitForJS('return window.echoDemo.state.relayed >= 3;', 30);
        $I->click('[data-toggle]'); // pause

        $state = $I->executeJS('return {posted: echoDemo.state.posted, relayed: echoDemo.state.relayed, lat: echoDemo.state.latencies.length};');
        $I->assertGreaterThanOrEqual(3, $state['posted']);
        $I->assertGreaterThanOrEqual(3, $state['lat'], 'every relayed frame was matched to its POST by trace id');
        $I->waitForJS('return document.querySelectorAll("[data-demo-admin] .demo-admin-row").length >= 3;', 5);
        $I->see('matched  e2e', '[data-trace]');
        $I->see('redis PUBLISH', '[data-trace]');

        // The real admin panel sees them as demo rows.
        $creds = $I->adminCredentials();
        $I->openNewTab();
        $I->amOnUrl($I->backendUrl('/login'));
        $I->fillField('#loginform-username', $creds['username']);
        $I->fillField('#loginform-password', $creds['password']);
        $I->click('#login-button');
        $I->amOnUrl($I->backendUrl('/?filter=demo'));
        $I->waitForElement('[data-rows] tr', 10);
        $I->assertGreaterThanOrEqual(3, count($I->grabMultiple('[data-rows] tr [data-slot="demo"]:not([hidden])')));

        // Reset on the demo page…
        $I->switchToPreviousTab();
        $I->click('[data-reset]');
        $I->waitForText('purged', 10, '[data-trace]');
        $I->waitForJS('return document.querySelectorAll("[data-demo-admin] .demo-admin-row").length === 0;', 10);

        // …and the demo rows are gone for real.
        $I->switchToNextTab();
        $I->reloadPage();
        $I->waitForElement('[data-admin-live]', 10);
        $I->assertSame([], $I->grabMultiple('[data-rows] tr'));
        $I->see('0', '.cp-tab.is-active .mono');
    }
}
