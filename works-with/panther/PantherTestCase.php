<?php

declare(strict_types=1);

// tests/PantherTestCase.php of a Symfony app that tests with symfony/panther. The CI job
// `works-with` runs panther/WorksWithTest.php with it. The lines between the README markers stand
// word for word in the README; a test compares them.

namespace App\Tests;

use PHPUnit\Framework\Attributes\BeforeClass;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Panther\PantherTestCase as BaseTestCase;

abstract class PantherTestCase extends BaseTestCase
{
    // README: begin
    #[BeforeClass]
    final public static function usePinnedPair(): void
    {
        $binaries = Installer::fromEnvironment(getenv())->binaries(dirname(__DIR__).'/'.Pin::FILE, Platform::current());
        $_SERVER['PANTHER_CHROME_BINARY'] = $binaries->path(Binary::ChromeHeadlessShell);
        $_SERVER['PANTHER_NO_SANDBOX'] = PHP_OS_FAMILY === 'Linux' ? '1' : '0';
        // createPantherClient() starts the first chromedriver that it finds in PATH.
        putenv('PATH='.dirname($binaries->path(Binary::Chromedriver)).PATH_SEPARATOR.getenv('PATH'));
    }
    // README: end
}
