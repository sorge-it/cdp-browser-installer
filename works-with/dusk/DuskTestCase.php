<?php

declare(strict_types=1);

// tests/DuskTestCase.php of the Laravel app that the CI job `works-with` creates for laravel/dusk.
// The lines between the README markers stand word for word in the README; a test compares them.

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

abstract class DuskTestCase extends BaseTestCase
{
    // README: begin
    private static ?Binaries $binaries = null;

    #[BeforeClass]
    final public static function prepare(): void
    {
        static::useChromedriver(self::binaries()->path(Binary::Chromedriver));
        static::startChromeDriver(['--port=9515']);
    }

    protected function driver(): RemoteWebDriver
    {
        $options = new ChromeOptions()->setBinary(self::binaries()->path(Binary::ChromeHeadlessShell));
        $options->addArguments(PHP_OS_FAMILY === 'Linux' ? ['--headless', '--no-sandbox'] : ['--headless']);
        $options->addArguments(['--window-size=1920,1080']);

        return RemoteWebDriver::create('http://localhost:9515', DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options));
    }

    private static function binaries(): Binaries
    {
        // Once, in prepare(), where getenv() holds .env only under php artisan dusk.
        return self::$binaries ??= Installer::fromEnvironment(getenv())->binaries(dirname(__DIR__).'/'.Pin::FILE, Platform::current());
    }
    // README: end
}
