<?php

declare(strict_types=1);

// symfony/panther starts the installed ChromeDriver, which starts the installed browser. The CI
// job `works-with` runs this script in this directory, after `vendor/bin/cdp-browser install`. The
// lines between the README markers stand word for word in the README; a test compares them.

use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Panther\Client;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/check.php';

// README: begin
$binaries = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current());
$_SERVER['PANTHER_CHROME_BINARY'] = $binaries->path(Binary::ChromeHeadlessShell);
$_SERVER['PANTHER_NO_SANDBOX'] = PHP_OS_FAMILY === 'Linux' ? '1' : '0';
$client = Client::createChromeClient($binaries->path(Binary::Chromedriver));
// README: end

try {
    $client->request('GET', 'data:text/html,<p id="ok">panther</p>');
    $text = $client->getCrawler()->filter('#ok')->text();
    $agent = (string) $client->executeScript('return navigator.userAgent');
    $chromedriver = (string) ($client->getWebDriver()->getCapabilities()->getCapability('chrome')['chromedriverVersion'] ?? '');
} finally {
    $client->quit();
}

check('symfony/panther', $text, 'panther', $agent, $chromedriver);
