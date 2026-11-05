<?php

declare(strict_types=1);

// php-webdriver/webdriver starts the installed ChromeDriver, which starts the installed browser.
// The CI job `works-with` runs this script here, after `vendor/bin/cdp-browser install`. The lines
// between the README markers stand word for word in the README; a test compares them.

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\WebDriverBy;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/check.php';

// README: begin
$binaries = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current());
$options = new ChromeOptions()->setBinary($binaries->path(Binary::ChromeHeadlessShell));
$options->addArguments(PHP_OS_FAMILY === 'Linux' ? ['--headless', '--no-sandbox'] : ['--headless']);
$capabilities = DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options);
$service = new ChromeDriverService($binaries->path(Binary::Chromedriver), 9515, ['--port=9515']);
$driver = ChromeDriver::start($capabilities, $service);
// README: end

try {
    $driver->get('data:text/html,<p id="ok">php-webdriver</p>');
    $text = $driver->findElement(WebDriverBy::id('ok'))->getText();
    $browser = (string) $driver->getCapabilities()->getCapability('browserVersion');
    $chromedriver = (string) ($driver->getCapabilities()->getCapability('chrome')['chromedriverVersion'] ?? '');
} finally {
    $driver->quit();
}

check('php-webdriver/webdriver', $text, 'php-webdriver', $browser, $chromedriver);
