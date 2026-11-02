<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\SystemLibraries;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->tools = new FakeTools($this->directory.'/tools');
    $this->pinFile = $this->directory.'/.chrome-version';
    pinFor('155.0.8059.39', browserArchive($this->directory.'/source.zip', Platform::LinuxX64))->write($this->pinFile);
});

it('downloads nothing on musl, and names the two steps', function (string $command): void {
    expect(fn (): int => $this->cli([], musl: true)->run(['command' => $command, '--pin-file' => $this->pinFile]))
        ->toThrow(installerException("Chrome for Testing is built against glibc and does not start on musl, as on Alpine. Install the system's browser (`cdp-browser deps` runs `apk add chromium`), then set CDP_BROWSER_BINARY to its path, such as /usr/bin/chromium."))
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([
    'install' => 'install',
    // Not "Run `cdp-browser install`": install refuses there too.
    'path' => 'path',
]);

it("uses the system's browser and prints its path", function (): void {
    $browser = $this->tools->browser();
    $tester = $this->cli([Installer::OWN_BROWSER => $browser], musl: true);

    $tester->run(['command' => 'install']);

    $installed = $tester->getDisplay();
    $tester->run(['command' => 'path']);

    expect($installed)->toBe(sprintf("The system's browser is at %s (CDP_BROWSER_BINARY).\n", $browser))
        ->and(trim($tester->getDisplay()))->toBe($browser)
        ->and($this->tools->calls('curl'))->toBe([])
        // `install` and `path` each check the browser.
        ->and($this->tools->calls('chromium'))->toBe(['--version', '--version']);
});

it("checks the system's browser as root without --allow-root: install then writes nothing", function (): void {
    $browser = $this->tools->browser();
    $tester = $this->cli([Installer::OWN_BROWSER => $browser], musl: true, user: 0);

    expect($tester->run(['command' => 'install']))->toBe(0)
        ->and($tester->getDisplay())->toBe(sprintf("The system's browser is at %s (CDP_BROWSER_BINARY).\n", $browser))
        ->and(file_exists($this->directory.'/cache'))->toBeFalse();
});

it('prints only a system browser that install takes', function (string $value, string $message): void {
    [$value, $message] = refusedBrowser($this->directory, $value, $message);

    expect(fn (): int => $this->cli([Installer::OWN_BROWSER => $value], musl: true)->run(['command' => 'path']))
        ->toThrow(installerException($message));
})->with('system browsers that install refuses');

it('gives a test tool one call for both ways', function (): void {
    $browser = $this->tools->browser();
    $own = Installer::fromEnvironment([Installer::OWN_BROWSER => $browser, 'HOME' => $this->directory], $this->tools->commandLine(), musl: true);

    expect($own->browser($this->pinFile, Platform::LinuxX64))->toBe($browser)
        ->and($own->ownBrowser())->toBe($browser);
});

it('needs an absolute path to a program that answers --version', function (string $value, string $message): void {
    [$value, $message] = refusedBrowser($this->directory, $value, $message);
    $installer = Installer::fromEnvironment([Installer::OWN_BROWSER => $value, 'HOME' => $this->directory], $this->tools->commandLine(), musl: true);

    expect(fn (): string => $installer->browser($this->pinFile, Platform::LinuxX64))->toThrow(installerException($message));
})->with('system browsers that install refuses');

dataset('system browsers that install refuses', [
    'relative' => ['chromium', 'CDP_BROWSER_BINARY must be an absolute path, not `chromium`.'],
    // Not "`cdp-browser deps` installs it": deps installs no browser at a path of the caller's choice.
    'missing' => ['/nowhere/chromium', 'CDP_BROWSER_BINARY `/nowhere/chromium` is not an executable file. Set CDP_BROWSER_BINARY to the path of an existing browser binary, such as /usr/bin/chromium.'],
    'a directory' => ['/', 'CDP_BROWSER_BINARY `/` is not an executable file.'],
    'a file that is not executable' => ['{browser}', 'CDP_BROWSER_BINARY `{browser}` is not an executable file.'],
]);

/**
 * The value and the message of the dataset, with `{browser}` as a file of mode 0644: not
 * executable, also for root.
 *
 * @return array{string, string}
 */
function refusedBrowser(string $directory, string $value, string $message): array
{
    $file = $directory.'/browser';
    file_put_contents($file, "#!/bin/sh\n");
    chmod($file, 0o644);

    return [strtr($value, ['{browser}' => $file]), strtr($message, ['{browser}' => $file])];
}

it('stops at a browser that fails on --version', function (): void {
    $browser = $this->tools->add('chromium', 'exit(1);');
    $installer = Installer::fromEnvironment([Installer::OWN_BROWSER => $browser, 'HOME' => $this->directory], $this->tools->commandLine(), musl: true);

    expect(fn (): string => $installer->browser($this->pinFile, Platform::LinuxX64))
        ->toThrow(static function (InstallerException $exception) use ($browser): void {
            // An exit code has no exception behind it.
            expect($exception::class)->toBe(InstallerException::class)
                ->and($exception->getMessage())->toContain(sprintf('CDP_BROWSER_BINARY `%1$s` does not answer --version. `%1$s` stopped with exit code 1', $browser))
                ->and($exception->getPrevious())->toBeNull();
        })
        ->and($this->tools->calls('chromium'))->toBe(['--version']);
});

it('gives the cause of a browser past its timeout as the previous exception', function (): void {
    $browser = $this->tools->add('chromium', 'sleep(5);');
    $installer = Installer::fromEnvironment([Installer::OWN_BROWSER => $browser], $this->tools->commandLine(timeout: 1), musl: true);

    expect(fn (): string => $installer->browser($this->pinFile, Platform::LinuxX64))
        ->toThrow(installerException('exceeded the timeout of 1 seconds.', ProcessTimedOutException::class));
});

it('installs the chromium of Alpine through apk as root, and names the variable', function (): void {
    $this->tools->packageManager('apk');
    $tester = $this->cli([], musl: true, user: 0);

    $tester->run(['command' => 'deps']);

    expect($this->tools->calls('apk'))->toBe(['add --no-cache '.implode(' ', SystemLibraries::APK)])
        ->and($this->tools->calls('apt-get'))->toBe([])
        ->and($tester->getDisplay())->toEndWith("The packages are installed. Set CDP_BROWSER_BINARY to the chromium of Alpine, such as /usr/bin/chromium; `install` then uses it.\n");
});
