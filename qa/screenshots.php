<?php
// Captures README screenshots from the isolated test stack through Selenium.
// docker compose --profile test run --rm --entrypoint php tester qa/screenshots.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverDimension;

$out = __DIR__ . '/../docs/screenshots';
$fe = getenv('E2E_FRONTEND_URL');
$be = getenv('E2E_BACKEND_URL');
$caps = DesiredCapabilities::chrome();
$opts = new ChromeOptions();
$opts->addArguments(['--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars']);
$caps->setCapability(ChromeOptions::CAPABILITY, $opts);
$d = RemoteWebDriver::create('http://' . getenv('SELENIUM_HOST') . ':4444', $caps);
$size = fn (int $w, int $h) => $d->manage()->window()->setSize(new WebDriverDimension($w, $h));
$wait = fn (string $js, int $s = 15) => $d->wait($s, 200)->until(fn () => $d->executeScript($js));

try {
    // Demo: let it run so the feed has real persona traffic.
    $size(1440, 1000);
    $d->get($fe . '/demo');
    $wait('return document.querySelector("[data-live]").dataset.state === "live"');
    $d->executeScript('const s=document.querySelector("[data-speed]"); s.value=900; s.dispatchEvent(new Event("input"));');
    $d->findElement(WebDriverBy::cssSelector('[data-play-big]'))->click();
    $wait('return window.echoDemo.state.relayed >= 7', 40);
    usleep(400000);
    $d->takeScreenshot("$out/demo.png");
    $d->findElement(WebDriverBy::cssSelector('[data-toggle]'))->click();

    // Admin with a live row flashing.
    $d->get($be . '/login');
    $d->findElement(WebDriverBy::id('loginform-username'))->sendKeys(getenv('ADMIN_USERNAME'));
    $d->findElement(WebDriverBy::id('loginform-password'))->sendKeys(getenv('ADMIN_PASSWORD'));
    $d->findElement(WebDriverBy::id('login-button'))->click();
    $wait('return document.querySelector("[data-live]") && document.querySelector("[data-live]").dataset.state === "live"');
    $d->executeScript('fetch("' . $fe . '/ws-token").catch(()=>{})');
    $http = new GuzzleHttp\Client(['cookies' => true]);
    preg_match('/name="csrf-token" content="([^"]+)"/', (string) $http->get($fe . '/')->getBody(), $m);
    $http->post($fe . '/comments', ['form_params' => ['Comment[author_name]' => 'Reviewer', 'Comment[body]' => 'Привет из формы — эта строка прилетела по WebSocket без перезагрузки.'],
        'headers' => ['X-Requested-With' => 'XMLHttpRequest', 'X-CSRF-Token' => $m[1]]]);
    $wait('return document.querySelector("[data-rows] tr.is-new")');
    usleep(250000);
    $d->takeScreenshot("$out/admin-live.png");

    // Public wall, desktop and mobile.
    $d->get($fe . '/');
    $wait('return document.querySelector("[data-live]").dataset.state === "live"');
    usleep(300000);
    $d->takeScreenshot("$out/wall.png");
    $size(390, 844);
    $d->navigate()->refresh();
    $wait('return document.querySelector("[data-live]").dataset.state === "live"');
    usleep(300000);
    $d->takeScreenshot("$out/wall-mobile.png");

    // Leave no demo data behind.
    $size(1440, 1000);
    $d->get($fe . '/demo');
    $d->findElement(WebDriverBy::cssSelector('[data-reset]'))->click();
    $wait('return document.querySelector("[data-trace]").textContent.includes("purged")');
} finally {
    $d->quit();
}
echo "screenshots written to docs/screenshots\n";
