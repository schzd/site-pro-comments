<?php

declare(strict_types=1);

namespace blackbox\tests\E2E;

use blackbox\tests\Support\E2ETester;

/**
 * The social-timeline behaviours: live arrivals between visitors, the
 * "↑ N новых" pill, likes that persist, and the mobile layout.
 *
 * @group e2e
 * @group step-S4
 */
final class PublicWallLiveCest
{
    public function otherVisitorsSeeNewPostsLive(E2ETester $I): void
    {
        $text = $I->uniqueText('e2e between visitors');

        $I->amOnUrl($I->frontendUrl('/'));
        $I->waitForElement('[data-live][data-state="live"]', 10);

        // A second visitor posts (plain HTTP, like another device would).
        $res = $I->postComment('Second Device', $text);
        $I->assertSame(201, $res['status']);

        $I->waitForText($text, 10, '[data-feed]');
        $I->seeElement('//article[contains(@class,"is-new")][contains(., "' . $text . '")]');
    }

    public function scrolledReaderGetsNewPostsPill(E2ETester $I): void
    {
        $text = $I->uniqueText('e2e pill');

        $I->amOnUrl($I->frontendUrl('/'));
        $I->waitForElement('[data-live][data-state="live"]', 10);
        $I->executeJS('document.body.style.paddingBottom = "4000px"; window.scrollTo(0, 2500);');

        $I->postComment('Pill Bot', $text);
        $I->waitForElementVisible('[data-new-pill]', 10);
        $I->see('1', '[data-new-count]');
        $I->dontSeeElement('//article[contains(., "' . $text . '")]');

        $I->click('[data-new-pill]');
        $I->waitForElementVisible('//article[contains(., "' . $text . '")]', 10);
        $I->dontSeeElement('[data-new-pill]:not([hidden])');
    }

    public function likeIsOptimisticAndPersists(E2ETester $I): void
    {
        $text = $I->uniqueText('e2e like');
        $I->postComment('Like Bot', $text);

        $I->amOnUrl($I->frontendUrl('/'));
        $card = '//article[contains(., "' . $text . '")]';
        $I->waitForElement($card, 10);
        $I->click($card . '//button[@data-like]');
        // Wait for the server to confirm (optimistic UI flips before the POST lands).
        $I->waitForElement($card . '//button[contains(@class,"is-liked")][not(@data-pending)]', 5);
        $I->see('1', $card . '//*[@data-slot="likes"]');

        $I->reloadPage();
        $I->waitForElement($card . '//button[contains(@class,"is-liked")]', 10);
        $I->see('1', $card . '//*[@data-slot="likes"]');
    }

    /** @group step-S1 */
    public function mobileComposerDocksToTheBottomWithoutHorizontalScroll(E2ETester $I): void
    {
        $I->resizeWindow(375, 812);
        $I->amOnUrl($I->frontendUrl('/'));
        $I->waitForElement('[data-composer]', 10);
        $layout = $I->executeJS(<<<'JS'
            const c = document.querySelector('[data-composer]').getBoundingClientRect();
            return {
                position: getComputedStyle(document.querySelector('[data-composer]')).position,
                bottomGap: Math.round(window.innerHeight - c.bottom),
                overflowX: document.documentElement.scrollWidth - window.innerWidth,
                tapTarget: document.querySelector('[data-submit]').getBoundingClientRect().height,
            };
        JS);
        $I->resizeWindow(1400, 900);

        $I->assertSame('fixed', $layout['position']);
        $I->assertLessThanOrEqual(1, $layout['bottomGap'], 'composer must sit on the bottom edge');
        $I->assertLessThanOrEqual(0, $layout['overflowX'], 'no horizontal scroll at 375px');
        $I->assertGreaterThanOrEqual(40, $layout['tapTarget']);
    }
}
