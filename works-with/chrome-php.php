<?php

declare(strict_types=1);

// chrome-php/chrome starts the installed browser and reads a page through it. The CI job
// `works-with` runs this script in this directory, after `vendor/bin/cdp-browser install`. The
// lines between the README markers stand word for word in the README; a test compares them.

use HeadlessChromium\BrowserFactory;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/check.php';

// README: begin
$binary = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current())->path(Binary::ChromeHeadlessShell);
$browser = new BrowserFactory($binary)->createBrowser(['noSandbox' => PHP_OS_FAMILY === 'Linux']);
// README: end

try {
    $page = $browser->createPage();
    $page->navigate('data:text/html,<p id="ok">chrome-php</p>')->waitForNavigation();
    $text = $page->evaluate('document.getElementById("ok")?.textContent ?? null')->getReturnValue();
    $agent = (string) $page->evaluate('navigator.userAgent')->getReturnValue();
} finally {
    $browser->close();
}

check('chrome-php/chrome', is_string($text) ? $text : null, 'chrome-php', $agent);
