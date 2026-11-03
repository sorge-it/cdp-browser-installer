<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * The paths of the pair: the browser and the ChromeDriver of the same version. A test tool that
 * speaks CDP starts the browser; a WebDriver tool starts ChromeDriver and gives it the browser.
 */
final readonly class Binaries
{
    public function __construct(private string $chromeHeadlessShell, private string $chromedriver) {}

    public function path(Binary $binary): string
    {
        return match ($binary) {
            Binary::ChromeHeadlessShell => $this->chromeHeadlessShell,
            Binary::Chromedriver => $this->chromedriver,
        };
    }
}
