<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use SorgeIt\CdpBrowserInstaller\Console\Cli;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The test case of every test: the properties that a `beforeEach` sets, with their types. The
 * extension in tests/PHPStan tells PHPStan that `$this` in a closure of Pest is this class.
 */
abstract class TestCase extends BaseTestCase
{
    /** A new empty directory of the test, from `temporaryDirectory()`. */
    public string $directory;

    /** The archive of chrome-headless-shell that the fake curl serves. */
    public string $archive;

    /** The archive of chromedriver that the fake curl serves. */
    public string $chromedriverArchive;

    public Pin $pin;

    public string $pinFile;

    public FakeTools $tools;

    public Installer $installer;

    /** The directory of the pair for the version and the platform. */
    public string $target;

    /**
     * The application of `bin/cdp-browser`, with the cache below the directory of the test and
     * the fake tools. It throws each exception, so that a test can expect it.
     *
     * @param  array<string, string>  $environment  besides CDP_BROWSER_PATH
     */
    final public function cli(array $environment = [], int $user = 1000, Platform $platform = Platform::LinuxX64, bool $musl = false): ApplicationTester
    {
        $application = Cli::application($environment + ['CDP_BROWSER_PATH' => $this->directory.'/cache'], $user, static fn (): Platform => $platform, $this->tools->commandLine(), $musl);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return new ApplicationTester($application);
    }
}
