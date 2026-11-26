<?php

declare(strict_types=1);

namespace blackbox\tests\E2E;

use blackbox\tests\Support\E2ETester;

/**
 * THE walkthrough, in a real browser: one comment from the public form to the
 * admin list over WebSocket, then edited and deleted, with the visitor's tab
 * following every change live. Two tabs, two origins, zero reloads.
 *
 * @group e2e
 * @group step-S1
 * @group step-S4
 * @group step-S8
 * @group step-S9
 */
final class LiveCommentFlowCest
{
    private function loginAdmin(E2ETester $I): void
    {
        $creds = $I->adminCredentials();
        $I->amOnUrl($I->backendUrl('/login'));
        $I->fillField('#loginform-username', $creds['username']);
        $I->fillField('#loginform-password', $creds['password']);
        $I->click('#login-button');
        $I->waitForElement('[data-admin-live]', 10);
        $I->waitForElement('[data-live][data-state="live"]', 10);
    }

    public function commentTravelsFromFormToAdminListLive(E2ETester $I): void
    {
        $text = $I->uniqueText('e2e live');
        $edited = $I->uniqueText('e2e edited');

        // Tab 1 — administrator, socket LIVE. Plant a marker that a reload would wipe.
        $this->loginAdmin($I);
        $I->executeJS('window.__e2eNoReload = "kept";');

        // Tab 2 — visitor posts through the real form, no login.
        $I->openNewTab();
        $I->amOnUrl($I->frontendUrl('/'));
        $I->waitForElement('[data-live][data-state="live"]', 10);
        $I->dontSeeElement('input[type=password]');
        $I->fillField('#comment-author_name', 'E2E Visitor');
        $I->fillField('#comment-body', $text);
        $I->click('[data-submit]');
        $I->waitForText($text, 10, '[data-feed]');
        $I->seeInField('#comment-body', '');

        // Tab 1 — the row arrived over the WebSocket, flashed, page never reloaded.
        $I->switchToPreviousTab();
        $I->waitForText($text, 10, '[data-rows]');
        $I->seeElement('//tr[contains(@class,"is-new")][contains(., "' . $text . '")]');
        $I->see('E2E Visitor', '[data-rows] tr:first-child');
        // Visibility, not just attributes (REG-002: CSS once overrode [hidden]).
        $I->dontSeeElement('//tr[contains(., "' . $text . '")]//*[@data-slot="demo"]');
        $I->dontSeeElement('[data-banner]');
        $I->assertSame('kept', $I->executeJS('return window.__e2eNoReload;'), 'admin page must not reload');
        $I->assertGreaterThanOrEqual(1, (int) $I->grabTextFrom('[data-live-count]'));

        // Admin edits the comment…
        $I->click('//tr[contains(., "' . $text . '")]//a[contains(., "Edit")]');
        $I->waitForElement('#comment-form', 10);
        $I->fillField('#comment-body', $edited);
        $I->click('#save-button');
        $I->waitForText('saved', 10, '.flash');
        $I->see($edited, '[data-rows]');

        // …and the visitor's feed follows live.
        $I->switchToNextTab();
        $I->waitForText($edited, 10, '[data-feed]');
        $I->dontSee($text, '[data-feed]');

        // Admin deletes it…
        $I->switchToPreviousTab();
        $I->click('//tr[contains(., "' . $edited . '")]//button[contains(., "Delete")]');
        $I->acceptPopup();
        $I->waitForText('deleted', 10, '.flash');
        $I->dontSee($edited, '[data-rows]');

        // …and it disappears from the public feed without a reload.
        $I->switchToNextTab();
        $I->waitForElementNotVisible('//article[contains(., "' . $edited . '")]', 10);
    }

    /** @group step-S2 */
    public function composerBlocksEmptyPostsAndShowsServerErrors(E2ETester $I): void
    {
        $I->amOnUrl($I->frontendUrl('/'));
        $I->waitForElement('[data-submit]', 10);
        $I->seeElement('[data-submit][disabled]');
        $I->fillField('#comment-body', 'x');
        $I->fillField('#comment-author_name', 'A'); // too short (server rule)
        $I->click('[data-submit]');
        $I->waitForText('минимум 2', 10, '[data-error-for="author_name"]');
        $I->seeElement('.field.has-error #comment-author_name');
    }
}
