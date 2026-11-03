<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Platform;

it('names the archive, the binary and the variable of each binary as Chrome for Testing and the README do', function (Binary $binary, string $directory, string $path, string $variable, string $alpine): void {
    expect($binary->directory(Platform::MacArm64))->toBe($directory)
        ->and($binary->path(Platform::MacArm64))->toBe($path)
        ->and($binary->variable())->toBe($variable)
        ->and($binary->alpinePath())->toBe($alpine);
})->with([
    [Binary::ChromeHeadlessShell, 'chrome-headless-shell-mac-arm64', 'chrome-headless-shell-mac-arm64/chrome-headless-shell', 'CDP_BROWSER_CHROME', '/usr/bin/chromium'],
    [Binary::Chromedriver, 'chromedriver-mac-arm64', 'chromedriver-mac-arm64/chromedriver', 'CDP_BROWSER_CHROMEDRIVER', '/usr/bin/chromedriver'],
]);

it('gives the path of each binary of the pair', function (): void {
    $binaries = new Binaries('/a/chrome-headless-shell', '/b/chromedriver');

    expect($binaries->path(Binary::ChromeHeadlessShell))->toBe('/a/chrome-headless-shell')
        ->and($binaries->path(Binary::Chromedriver))->toBe('/b/chromedriver');
});
