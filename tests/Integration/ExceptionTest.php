<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\ChecksumException;
use SorgeIt\CdpBrowserInstaller\CommandLine;
use SorgeIt\CdpBrowserInstaller\Console\PinCommand;
use SorgeIt\CdpBrowserInstaller\DownloadException;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;
use SorgeIt\SafeUnzip\Exception\ExtractionMismatchException;
use SorgeIt\SafeUnzip\Exception\FilesystemException;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Exception\ToolException;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->archive = browserArchive($this->directory.'/source.zip', Platform::LinuxArm64);
    $this->chromedriverArchive = chromedriverArchive($this->directory.'/chromedriver.zip', Platform::LinuxArm64);
    $this->pin = pinFor('155.0.8059.39', $this->archive, $this->chromedriverArchive, Platform::LinuxArm64);
    $this->pinFile = $this->directory.'/.chrome-version';
    $this->pin->write($this->pinFile);
    $this->tools = new FakeTools($this->directory.'/tools');
    $this->tools->serve('155.0.8059.39', Binary::ChromeHeadlessShell, Platform::LinuxArm64, $this->archive);
    $this->tools->serve('155.0.8059.39', Binary::Chromedriver, Platform::LinuxArm64, $this->chromedriverArchive);

    $this->installer = new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: false);
    $this->target = $this->directory.'/cache/155.0.8059.39/linux-arm64';
});

it('throws an InstallerException, and no subclass, for each cause but the download and the SHA-256', function (Closure $cause): void {
    expect(fn (): mixed => underErrorExceptions(fn (): mixed => $cause->call($this)))->toThrow(installerException());
})->with([
    'a timeout below 0' => [fn (): Installer => Installer::fromEnvironment([Installer::TIMEOUT => '-1', 'HOME' => '/home/me'], musl: false)],
    'install as root' => [onTestCase(fn (): int => $this->cli(user: 0, platform: Platform::LinuxArm64)->run(['command' => 'install', '--pin-file' => $this->pinFile]))],
    'no pin file' => [onTestCase(fn (): Pin => Pin::read($this->directory.'/missing'))],
    'no platform of Chrome for Testing' => [fn (): Platform => Platform::detect('Linux', 'ppc64le')],
    'musl' => [onTestCase(fn (): Binaries => new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: true)->install($this->pin, Platform::LinuxArm64))],
    'path on musl' => [onTestCase(fn (): int => $this->cli(platform: Platform::LinuxArm64, musl: true)->run(['command' => 'path', 'binary' => 'chromedriver', '--pin-file' => $this->pinFile]))],
    'path without the name of a binary' => [onTestCase(fn (): int => $this->cli(platform: Platform::LinuxArm64)->run(['command' => 'path', '--pin-file' => $this->pinFile]))],
    // With a cache directory, only the rule of the pair can stop it.
    'only one variable of the system pair' => [onTestCase(fn (): Installer => Installer::fromEnvironment([Installer::OWN_CHROMEDRIVER => $this->tools->chromedriver(), 'CDP_BROWSER_PATH' => $this->directory.'/cache'], $this->tools->commandLine(), musl: false))],
    'a system browser that does not exist' => [onTestCase(fn (): Binaries => Installer::fromEnvironment([Installer::OWN_CHROME => $this->directory.'/chromium', Installer::OWN_CHROMEDRIVER => $this->tools->chromedriver()], $this->tools->commandLine(), musl: false)->binaries($this->pinFile, Platform::LinuxArm64))],
    'a system chromedriver that does not answer' => [onTestCase(fn (): Binaries => Installer::fromEnvironment([Installer::OWN_CHROME => $this->tools->browser(), Installer::OWN_CHROMEDRIVER => $this->tools->add('chromedriver', 'exit(3);')], $this->tools->commandLine(), musl: false)->binaries($this->pinFile, Platform::LinuxArm64))],
    'a pin file of version 1' => [onTestCase(function (): Pin {
        file_put_contents($this->pinFile, json_encode(['version' => '155.0.8059.39', 'sha256' => ['linux64' => str_repeat('a', 64)]]));

        return Pin::read($this->pinFile);
    })],
    'a missing tool' => [onTestCase(fn (): string => $this->tools->commandLine(system: false)->run(['unzip']))],
    // The bytes match the pin: the archive of the source is wrong, not its SHA-256.
    'an archive of the pinned bytes without the binary' => [onTestCase(function (): Binaries {
        $archive = zipArchive($this->directory.'/readme.zip', ['readme' => ['x', 0o100644]]);
        $this->tools->serve('155.0.8059.39', Binary::Chromedriver, Platform::LinuxArm64, $archive);

        return $this->installer->install(pinFor('155.0.8059.39', $this->archive, $archive, Platform::LinuxArm64), Platform::LinuxArm64);
    })],
    'a cache directory that is a file' => [onTestCase(function (): Binaries {
        touch($this->directory.'/cache');

        return $this->installer->install($this->pin, Platform::LinuxArm64);
    })],
    'a lock that another install holds' => [onTestCase(function (): Binaries {
        mkdir($this->target, 0o755, true);
        $held = new SplFileObject($this->target.'/.lock', 'c');
        $held->flock(LOCK_EX);

        return new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: false, lockTimeout: 0)->install($this->pin, Platform::LinuxArm64);
    })],
    'a pair that is not installed' => [onTestCase(fn (): int => $this->cli(platform: Platform::LinuxArm64)->run(['command' => 'path', 'binary' => 'chrome-headless-shell', '--pin-file' => $this->pinFile]))],
    // The source answers with status 404; a retry does not help.
    'a pinned version that does not exist' => [onTestCase(fn (): Binaries => $this->installer->install(pinFor('1.0.0.0', $this->archive, $this->chromedriverArchive, Platform::LinuxArm64), Platform::LinuxArm64))],
    'a version for pin that does not exist' => [onTestCase(fn (): int => $this->cli(platform: Platform::LinuxArm64)->run(['command' => 'pin', 'version' => '1.0.0.0', '--pin-file' => $this->directory.'/pinned']))],
    // unzip reads `[` in the path of the archive as a pattern.
    'a cache directory with a bracket' => [onTestCase(fn (): Installer => new Installer($this->tools->commandLine(), $this->directory.'/cache[1]'))],
]);

it('throws an InstallerException for each exception of safe-unzip, and keeps that exception as the previous one', function (Closure $exception, Closure $arrange): void {
    $arranged = $arrange->call($this);
    $installer = $arranged instanceof Installer ? $arranged : $this->installer;

    expect(fn (): mixed => underErrorExceptions(fn (): Binaries => $installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow($exception)
        ->and(glob($this->target.'/chrome-headless-shell-linux-arm64'))->toBe([]);
})->with([
    'bytes that are no ZIP archive' => [installerException('', InvalidArchiveException::class), onTestCase(function (): null {
        file_put_contents($this->directory.'/none.zip', 'no zip');
        $this->tools->serve('155.0.8059.39', Binary::ChromeHeadlessShell, Platform::LinuxArm64, $this->directory.'/none.zip');
        $this->pin = pinFor('155.0.8059.39', $this->directory.'/none.zip', $this->chromedriverArchive, Platform::LinuxArm64);

        return null;
    })],
    'an entry outside the profile' => [installerException('', RefusedException::class), onTestCase(function (): null {
        $archive = zipArchive($this->directory.'/link.zip', ['chrome-headless-shell-linux-arm64/link' => ['/etc/passwd', 0o120777]]);
        $this->tools->serve('155.0.8059.39', Binary::ChromeHeadlessShell, Platform::LinuxArm64, $archive);
        $this->pin = pinFor('155.0.8059.39', $archive, $this->chromedriverArchive, Platform::LinuxArm64);

        return null;
    })],
    // unzip writes one more file than the listing names.
    'a tree that differs from the listing' => [installerException('', ExtractionMismatchException::class), onTestCase(function (): null {
        $this->tools->around('unzip', '', '$target = array_search("-d", $argv, true); if ($target !== false) { file_put_contents($argv[$target + 1]."/extra", "x"); }');

        return null;
    })],
    // While unzip writes the tree, another process writes to the archive that safe-unzip hashed.
    'an archive that changed after its hash' => [installerException('', FilesystemException::class), onTestCase(function (): null {
        $this->tools->around('unzip', '', 'if (in_array("-d", $argv, true)) { foreach ($argv as $argument) { if (str_contains($argument, ".zip.part-")) { file_put_contents($argument, "x", FILE_APPEND); } } }');

        return null;
    })],
    // L2: after the check pass, the directory of the tree takes no new directory.
    'a target that takes nothing' => [installerException('', RefusedException::class), onTestCase(function (): null {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Root writes into a directory of mode 0555.');
        }

        $this->tools->around('unzip', sprintf('if (in_array("-p", $argv, true)) { foreach (glob(%s."/.unpack-*") ?: [] as $directory) { chmod($directory, 0o555); } }', var_export($this->target, true)));

        return null;
    })],
    // The archive is in the cache, so no curl runs under the timeout of a second: on a busy
    // machine, the fake curl alone can take that long.
    'zipinfo past its timeout' => [installerException('', ToolException::class), onTestCase(function (): Installer {
        mkdir($this->target, 0o755, true);
        copy($this->archive, $this->target.'/chrome-headless-shell-linux-arm64.zip');
        $this->tools->add('zipinfo', 'sleep(5);');

        return new Installer($this->tools->commandLine(timeout: 1), $this->directory.'/cache', musl: false);
    })],
]);

it('names a missing curl as a tool of the host, not as a download', function (): void {
    // The real rm, unzip and zipinfo, and no curl.
    $installer = new Installer(new CommandLine(['PATH' => $this->directory.'/tools/system']), $this->directory.'/cache', musl: false);

    expect(fn (): Binaries => $installer->install($this->pin, Platform::LinuxArm64))
        ->toThrow(installerException('`curl` is not installed. On Debian, Ubuntu and Alpine, `cdp-browser deps` installs it.'));
});

it('keeps the first error when the removal in its finally fails', function (string $command): void {
    $archive = zipArchive($this->directory.'/readme.zip', ['readme' => ['x', 0o100644]]);

    foreach (Platform::cases() as $platform) {
        $this->tools->serve('155.0.8059.39', Binary::ChromeHeadlessShell, $platform, $archive);
    }

    // Each removal fails, as on a file system that turned read-only.
    $this->tools->add('rm', 'fwrite(STDERR, "rm: read-only file system\n"); exit(1);');
    $cause = $command === 'install'
        ? fn (): Binaries => $this->installer->install(pinFor('155.0.8059.39', $archive, $this->chromedriverArchive, Platform::LinuxArm64), Platform::LinuxArm64)
        : fn (): int => new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000))->execute(['version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']);

    try {
        expect($cause)->toThrow(installerException('does not contain chrome-headless-shell-'))
            ->and($this->tools->calls('rm'))->not()->toBe([]);
    } finally {
        array_map(removeDirectory(...), pinDirectories($this->tools));
    }
})->with(['install', 'pin']);

it('names a failed removal when no error came first', function (): void {
    foreach (Platform::cases() as $platform) {
        $this->tools->serve('155.0.8059.39', Binary::ChromeHeadlessShell, $platform, browserArchive($this->directory.'/'.$platform->value.'.zip', $platform));
        $this->tools->serve('155.0.8059.39', Binary::Chromedriver, $platform, chromedriverArchive($this->directory.'/chromedriver-'.$platform->value.'.zip', $platform));
    }

    $this->tools->add('rm', 'fwrite(STDERR, "rm: read-only file system\n"); exit(1);');

    try {
        expect(fn (): int => new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000))->execute(['version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']))
            ->toThrow(installerException('`rm` stopped with exit code 1: rm: read-only file system'))
            ->and(file_exists($this->directory.'/pinned'))->toBeFalse();
    } finally {
        array_map(removeDirectory(...), pinDirectories($this->tools));
    }
});

it('stops at a timeout below 0, and takes 0', function (): void {
    expect(fn (): CommandLine => new CommandLine(timeout: -1))
        ->toThrow(installerException('The timeout of a tool is -1 seconds. Use 0 for no limit, or a positive value.'))
        ->and(fn (): Installer => new Installer(new CommandLine, null, lockTimeout: -1))
        ->toThrow(installerException('The lock timeout is -1 seconds. Use 0 for no wait, or a positive value.'))
        ->and(new CommandLine(timeout: 0)->timeout())->toBe(0)
        ->and(new Installer(new CommandLine(timeout: 0), null, lockTimeout: 0))->toBeInstanceOf(Installer::class);
});

it('names an exception of the installer with the exception behind it', function (): void {
    $previous = new RuntimeException('behind');
    $exception = new InstallerException('in front', $previous);

    expect($exception->getMessage())->toBe('in front')
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->getCode())->toBe(1);
});

it('gives each exception class its exit code as its code', function (): void {
    expect(new InstallerException('x')->getCode())->toBe(InstallerException::EXIT_CODE)->toBe(1)
        ->and(new DownloadException('x')->getCode())->toBe(DownloadException::EXIT_CODE)->toBe(75)
        ->and(new ChecksumException('x')->getCode())->toBe(ChecksumException::EXIT_CODE)->toBe(65);
});
