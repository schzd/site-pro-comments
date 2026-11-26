<?php
// Renders docs/banner.png (1280x640, also the GitHub social preview) and
// docs/video-poster.png from docs/brand/*.html through the Selenium service.
// The sources are served from the test frontend only for the duration of the run.
//   docker compose --profile test up -d
//   docker compose run --rm --entrypoint php tester qa/brand-shots.php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;

$src = __DIR__ . '/../docs/brand';
$tmp = __DIR__ . '/../frontend/web/_brand';
@mkdir($tmp);
$copied = [];
foreach (glob($src . '/*') as $f) {
    copy($f, $copied[] = $tmp . '/' . basename($f));
}

$caps = DesiredCapabilities::chrome();
$o = new ChromeOptions();
$o->addArguments(['--no-sandbox', '--disable-dev-shm-usage', '--hide-scrollbars', '--headless=new', '--window-size=1400,900']);
$caps->setCapability(ChromeOptions::CAPABILITY, $o);
$d = RemoteWebDriver::create('http://' . getenv('SELENIUM_HOST') . ':4444', $caps);
try {
    foreach (['banner' => 'banner.png', 'poster' => 'video-poster.png'] as $page => $file) {
        $d->get(getenv('E2E_FRONTEND_URL') . "/_brand/{$page}.html");
        $d->wait(10, 200)->until(fn () => $d->executeScript('return document.fonts.status === "loaded"'));
        usleep(500000);
        $d->findElement(WebDriverBy::cssSelector('.card'))->takeElementScreenshot(__DIR__ . "/../docs/{$file}");
        echo "docs/{$file}\n";
    }
} finally {
    $d->quit();
    array_map('unlink', $copied);
    @rmdir($tmp);
}
