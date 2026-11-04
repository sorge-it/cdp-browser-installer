<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
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
    pinFor('155.0.8059.39', browserArchive($this->directory.'/source.zip', Platform::LinuxX64), chromedriverArchive($this->directory.'/chromedriver.zip', Platform::LinuxX64), Platform::LinuxX64)->write($this->pinFile);
});

/**
 * The environment of the system's pair: both variables.
 *
 * @return array<string, string>
 */
function ownPair(FakeTools $tools): array
{
    return [Installer::OWN_CHROME => $tools->browser(), Installer::OWN_CHROMEDRIVER => $tools->chromedriver()];
}

it('downloads nothing on musl, and names the two steps before it reads the pin file', function (array $input, Closure $pinFile): void {
    /** @var array<string, string> $input */
    $file = $pinFile->call($this);
    assert(is_string($file));

    expect(fn (): int => $this->cli([], musl: true)->run(['--pin-file' => $file, ...$input]))
        ->toThrow(installerException("Chrome for Testing is built against glibc and does not start on musl, as on Alpine. Install the system's pair (`cdp-browser deps` runs `apk add chromium chromium-chromedriver`), then set CDP_BROWSER_CHROME to the browser, such as /usr/bin/chromium, and CDP_BROWSER_CHROMEDRIVER to the chromedriver, such as /usr/bin/chromedriver."))
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([
    'install' => [['command' => 'install']],
    // Not "Run `cdp-browser install`": install refuses there too.
    'path' => [['command' => 'path', 'binary' => 'chromedriver']],
])->with([
    'a pin file' => [onTestCase(fn (): string => $this->pinFile)],
    // Not "Write one with `cdp-browser pin`": install refuses after it.
    'no pin file' => [onTestCase(fn (): string => $this->directory.'/missing')],
    'a pin file of release 1.x' => [onTestCase(function (): string {
        file_put_contents($this->pinFile, json_encode(['version' => '155.0.8059.39', 'sha256' => ['linux64' => str_repeat('a', 64)]]));

        return $this->pinFile;
    })],
]);

it("uses the system's pair and prints the path of each binary", function (): void {
    $environment = ownPair($this->tools);
    $tester = $this->cli($environment, musl: true);

    $tester->run(['command' => 'install']);

    $installed = $tester->getDisplay();
    $tester->run(['command' => 'path', 'binary' => 'chrome-headless-shell']);
    $browser = trim($tester->getDisplay());
    $tester->run(['command' => 'path', 'binary' => 'chromedriver']);

    expect($installed)->toBe(sprintf(
        "The system's chrome-headless-shell is at %s (CDP_BROWSER_CHROME).\nThe system's chromedriver is at %s (CDP_BROWSER_CHROMEDRIVER).\n",
        $environment[Installer::OWN_CHROME],
        $environment[Installer::OWN_CHROMEDRIVER],
    ))
        ->and($browser)->toBe($environment[Installer::OWN_CHROME])
        ->and(trim($tester->getDisplay()))->toBe($environment[Installer::OWN_CHROMEDRIVER])
        ->and($this->tools->calls('curl'))->toBe([])
        // `install` and each `path` check both binaries.
        ->and($this->tools->calls('chromium'))->toBe(['--version', '--version', '--version'])
        ->and($this->tools->calls('chromedriver'))->toBe(['--version', '--version', '--version']);
});

it("checks the system's pair as root without --allow-root: install then writes nothing", function (): void {
    $environment = ownPair($this->tools);
    $tester = $this->cli($environment, musl: true, user: 0);

    expect($tester->run(['command' => 'install']))->toBe(0)
        ->and($tester->getDisplay())->toStartWith(sprintf("The system's chrome-headless-shell is at %s (CDP_BROWSER_CHROME).\n", $environment[Installer::OWN_CHROME]))
        ->and(file_exists($this->directory.'/cache'))->toBeFalse();
});

it('stops when only one variable of the pair is set, and names the other', function (string $set, string $missing): void {
    $environment = [$set => $set === Installer::OWN_CHROMEDRIVER ? $this->tools->chromedriver() : $this->tools->browser(), 'HOME' => $this->directory];

    expect(fn (): Installer => Installer::fromEnvironment($environment, $this->tools->commandLine(), musl: true))
        ->toThrow(installerException(sprintf('%s is set, but %s is not. Set both for the pair of the system, such as /usr/bin/chromium and /usr/bin/chromedriver, or neither for the pinned pair.', $set, $missing)))
        // `install` and `path` build the installer, so both stop, also on glibc; `pin` and `deps` build none.
        ->and(fn (): int => $this->cli([$set => '/usr/bin/x'])->run(['command' => 'install', '--pin-file' => $this->pinFile]))->toThrow(installerException(sprintf('%s is set, but %s is not.', $set, $missing)))
        ->and(fn (): int => $this->cli([$set => '/usr/bin/x'])->run(['command' => 'path', 'binary' => 'chromedriver', '--pin-file' => $this->pinFile]))->toThrow(installerException(sprintf('%s is set, but %s is not.', $set, $missing)))
        ->and($this->cli([$set => '/usr/bin/x'], platform: Platform::MacArm64)->run(['command' => 'deps']))->toBe(0)
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([
    'the browser' => [Installer::OWN_CHROME, Installer::OWN_CHROMEDRIVER],
    'chromedriver' => [Installer::OWN_CHROMEDRIVER, Installer::OWN_CHROME],
]);

it('stops at CDP_BROWSER_BINARY of release 1.x, uses none of its value, and names both variables', function (array $environment): void {
    /** @var array<string, string> $environment */
    $message = "CDP_BROWSER_BINARY is set, but release 2.x does not read it. Set CDP_BROWSER_CHROME and CDP_BROWSER_CHROMEDRIVER for the system's pair, such as /usr/bin/chromium and /usr/bin/chromedriver, or unset CDP_BROWSER_BINARY for the pinned pair.";
    $environment += ['CDP_BROWSER_BINARY' => $this->tools->browser()];

    expect(fn (): Installer => Installer::fromEnvironment($environment + ['HOME' => $this->directory], $this->tools->commandLine(), musl: false))
        ->toThrow(installerException($message))
        // `install` and `path` build the installer, so both stop with exit code 1.
        ->and(fn (): int => $this->cli($environment)->run(['command' => 'install', '--pin-file' => $this->pinFile]))->toThrow(installerException($message))
        ->and(fn (): int => $this->cli($environment)->run(['command' => 'path', 'binary' => 'chrome-headless-shell', '--pin-file' => $this->pinFile]))->toThrow(installerException($message))
        ->and($this->tools->calls('chromium'))->toBe([])
        ->and($this->tools->calls('curl'))->toBe([])
        ->and(file_exists($this->directory.'/cache'))->toBeFalse();
})->with([
    'alone' => [[]],
    "with the system's pair" => onTestCase(fn (): array => ownPair($this->tools)),
]);

it('takes an empty CDP_BROWSER_BINARY as not set', function (): void {
    $installer = Installer::fromEnvironment(['CDP_BROWSER_BINARY' => '', 'HOME' => $this->directory], $this->tools->commandLine(), musl: false);

    expect($installer->ownBinaries())->toBeNull();
});

it('prints only a system binary that install takes', function (Binary $binary, string $value, string $message): void {
    [$value, $message] = refusedBinary($this->directory, $value, $message);
    $environment = [$binary->variable() => $value] + ownPair($this->tools);

    expect(fn (): int => $this->cli($environment, musl: true)->run(['command' => 'path', 'binary' => 'chrome-headless-shell']))
        ->toThrow(installerException($message));
})->with('system binaries that install refuses');

it('gives a test tool one call for both ways', function (): void {
    $environment = ownPair($this->tools);
    $own = Installer::fromEnvironment($environment + ['HOME' => $this->directory], $this->tools->commandLine(), musl: true);
    $expected = new Binaries($environment[Installer::OWN_CHROME], $environment[Installer::OWN_CHROMEDRIVER]);

    expect($own->binaries($this->pinFile, Platform::LinuxX64))->toEqual($expected)
        ->and($own->ownBinaries())->toEqual($expected);
});

it('needs an absolute path to a program that answers --version, for each binary', function (Binary $binary, string $value, string $message): void {
    [$value, $message] = refusedBinary($this->directory, $value, $message);
    $installer = Installer::fromEnvironment([$binary->variable() => $value, 'HOME' => $this->directory] + ownPair($this->tools), $this->tools->commandLine(), musl: true);

    expect(fn (): Binaries => $installer->binaries($this->pinFile, Platform::LinuxX64))->toThrow(installerException($message));
})->with('system binaries that install refuses');

dataset('system binaries that install refuses', [
    'relative browser' => [Binary::ChromeHeadlessShell, 'chromium', 'CDP_BROWSER_CHROME must be an absolute path, not `chromium`.'],
    // Not "`cdp-browser deps` installs it": deps installs nothing at a path of the caller's choice.
    'missing browser' => [Binary::ChromeHeadlessShell, '/nowhere/chromium', 'CDP_BROWSER_CHROME `/nowhere/chromium` is not an executable file. Set CDP_BROWSER_CHROME to the path of an existing binary, such as /usr/bin/chromium.'],
    'a directory as the browser' => [Binary::ChromeHeadlessShell, '/', 'CDP_BROWSER_CHROME `/` is not an executable file.'],
    'a browser that is not executable' => [Binary::ChromeHeadlessShell, '{file}', 'CDP_BROWSER_CHROME `{file}` is not an executable file.'],
    'relative chromedriver' => [Binary::Chromedriver, 'chromedriver', 'CDP_BROWSER_CHROMEDRIVER must be an absolute path, not `chromedriver`.'],
    'missing chromedriver' => [Binary::Chromedriver, '/nowhere/chromedriver', 'CDP_BROWSER_CHROMEDRIVER `/nowhere/chromedriver` is not an executable file. Set CDP_BROWSER_CHROMEDRIVER to the path of an existing binary, such as /usr/bin/chromedriver.'],
    'a chromedriver that is not executable' => [Binary::Chromedriver, '{file}', 'CDP_BROWSER_CHROMEDRIVER `{file}` is not an executable file.'],
]);

/**
 * The value and the message of the dataset, with `{file}` as a file of mode 0644: not
 * executable, also for root.
 *
 * @return array{string, string}
 */
function refusedBinary(string $directory, string $value, string $message): array
{
    $file = $directory.'/binary';
    file_put_contents($file, "#!/bin/sh\n");
    chmod($file, 0o644);

    return [strtr($value, ['{file}' => $file]), strtr($message, ['{file}' => $file])];
}

it('stops at a binary that fails on --version', function (Binary $binary, string $tool): void {
    $failing = $this->tools->add($tool.'-failing', 'exit(1);');
    $installer = Installer::fromEnvironment([$binary->variable() => $failing, 'HOME' => $this->directory] + ownPair($this->tools), $this->tools->commandLine(), musl: true);

    expect(fn (): Binaries => $installer->binaries($this->pinFile, Platform::LinuxX64))
        ->toThrow(static function (InstallerException $exception) use ($binary, $failing): void {
            // An exit code has no exception behind it.
            expect($exception::class)->toBe(InstallerException::class)
                ->and($exception->getMessage())->toContain(sprintf('%1$s `%2$s` does not answer --version. `%2$s` stopped with exit code 1', $binary->variable(), $failing))
                ->and($exception->getPrevious())->toBeNull();
        })
        ->and($this->tools->calls($tool.'-failing'))->toBe(['--version']);
})->with([
    [Binary::ChromeHeadlessShell, 'chromium'],
    [Binary::Chromedriver, 'chromedriver'],
]);

it('gives the cause of a binary past its timeout as the previous exception', function (): void {
    $environment = ownPair($this->tools);
    $this->tools->add('chromedriver', 'sleep(5);');
    $installer = Installer::fromEnvironment($environment, $this->tools->commandLine(timeout: 1), musl: true);

    expect(fn (): Binaries => $installer->binaries($this->pinFile, Platform::LinuxX64))
        ->toThrow(installerException('exceeded the timeout of 1 seconds.', ProcessTimedOutException::class));
});

it('installs the chromium and chromium-chromedriver of Alpine through apk as root, and names both variables', function (): void {
    $this->tools->packageManager('apk');
    $tester = $this->cli([], musl: true, user: 0);

    $tester->run(['command' => 'deps']);

    expect($this->tools->calls('apk'))->toBe(['add --no-cache chromium chromium-chromedriver nss freetype harfbuzz ttf-freefont'])
        ->and(SystemLibraries::APK)->toContain('chromium', 'chromium-chromedriver')
        ->and($this->tools->calls('apt-get'))->toBe([])
        ->and($tester->getDisplay())->toEndWith("The packages are installed. Set CDP_BROWSER_CHROME to /usr/bin/chromium and CDP_BROWSER_CHROMEDRIVER to /usr/bin/chromedriver; `install` then uses this pair.\n");
});
