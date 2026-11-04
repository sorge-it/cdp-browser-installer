<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\CommandLine;
use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\DownloadException;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Libc;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use SorgeIt\SafeUnzip\Exception\RefusedException;
use SorgeIt\SafeUnzip\Exception\ToolException;
use SorgeIt\SafeUnzip\Exception\UnzipException;
use SorgeIt\SafeUnzip\Profile\Reason as Rule;
use Symfony\Component\Process\Process;

const VERSION = '155.0.8059.39';

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->archive = browserArchive($this->directory.'/source.zip', Platform::LinuxArm64);
    $this->chromedriverArchive = chromedriverArchive($this->directory.'/chromedriver.zip', Platform::LinuxArm64);
    $this->pin = pinFor(VERSION, $this->archive, $this->chromedriverArchive, Platform::LinuxArm64);
    $this->tools = new FakeTools($this->directory.'/tools');
    $this->tools->serve(VERSION, Binary::ChromeHeadlessShell, Platform::LinuxArm64, $this->archive);
    $this->tools->serve(VERSION, Binary::Chromedriver, Platform::LinuxArm64, $this->chromedriverArchive);

    $this->installer = new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: false);
    $this->target = $this->directory.'/cache/155.0.8059.39/linux-arm64';
});

/**
 * Installs a pair whose archive of the binary has the entries, and returns the path of that
 * binary. `{top}` in a name is the top directory of the archive.
 *
 * @param  array<string, array{string, int}>  $entries
 */
function installArchive(Binary $binary, array $entries): Closure
{
    return onTestCase(function () use ($binary, $entries): string {
        $top = $binary->directory(Platform::LinuxArm64);
        $named = [];

        foreach ($entries as $name => $entry) {
            $named[strtr($name, ['{top}' => $top, '{binary}' => $binary->value])] = $entry;
        }

        $archive = zipArchive($this->directory.'/bad.zip', $named);
        $this->tools->serve(VERSION, $binary, Platform::LinuxArm64, $archive);
        $pin = $binary === Binary::ChromeHeadlessShell ? pinFor(VERSION, $archive, $this->chromedriverArchive, Platform::LinuxArm64) : pinFor(VERSION, $this->archive, $archive, Platform::LinuxArm64);

        return $this->installer->install($pin, Platform::LinuxArm64)->path($binary);
    });
}

/**
 * The archives in the cache after the unpack of the binary failed: the install takes the browser
 * first, and fetches chromedriver only after it.
 *
 * @return list<string>
 */
function cachedArchives(string $target, Binary $failed): array
{
    return array_slice([$target.'/chrome-headless-shell-linux-arm64.zip', $target.'/chromedriver-linux-arm64.zip'], 0, $failed === Binary::ChromeHeadlessShell ? 1 : 2);
}

/**
 * The browser of the pair that `install` gives.
 */
function installBrowser(Installer $installer, Pin $pin, ?Closure $onDownload = null): string
{
    return $installer->install($pin, Platform::LinuxArm64, $onDownload)->path(Binary::ChromeHeadlessShell);
}

it('names the URL before each download, and none for an installed pair or a restored archive', function (): void {
    $urls = [];
    $onDownload = static function (string $url) use (&$urls): void {
        $urls[] = $url;
    };

    $binary = installBrowser($this->installer, $this->pin, $onDownload);
    installBrowser($this->installer, $this->pin, $onDownload);
    // A cache restored only the archives.
    removeDirectory(dirname($binary));
    unlink($this->target.'/.sha256-pair');
    installBrowser($this->installer, $this->pin, $onDownload);

    expect($urls)->toBe([Download::url(VERSION, Binary::ChromeHeadlessShell, Platform::LinuxArm64), Download::url(VERSION, Binary::Chromedriver, Platform::LinuxArm64)])
        ->and($this->tools->calls('curl'))->toHaveCount(2);
});

it('puts chrome-headless-shell and chromedriver side by side, and stamps the pair', function (): void {
    $binaries = $this->installer->install($this->pin, Platform::LinuxArm64);

    expect($binaries->path(Binary::ChromeHeadlessShell))->toBe($this->target.'/chrome-headless-shell-linux-arm64/chrome-headless-shell')
        ->and($binaries->path(Binary::Chromedriver))->toBe($this->target.'/chromedriver-linux-arm64/chromedriver')
        ->and(file_get_contents($binaries->path(Binary::ChromeHeadlessShell)))->toBe(BROWSER)
        ->and(file_get_contents($binaries->path(Binary::Chromedriver)))->toBe(CHROMEDRIVER)
        ->and(is_executable($binaries->path(Binary::Chromedriver)))->toBeTrue()
        ->and(file_get_contents($this->target.'/.sha256-pair'))->toBe(stampOf($this->pin, Platform::LinuxArm64))
        ->and(scandir($this->target))->toBe(['.', '..', '.lock', '.sha256-pair', 'chrome-headless-shell-linux-arm64', 'chrome-headless-shell-linux-arm64.zip', 'chromedriver-linux-arm64', 'chromedriver-linux-arm64.zip'])
        ->and($this->installer->paths($this->pin, Platform::LinuxArm64))->toEqual($binaries);
});

it('takes the pair as installed only when both binaries are there, and unpacks both again from the cache', function (Binary $missing): void {
    $binaries = $this->installer->install($this->pin, Platform::LinuxArm64);
    $other = $missing === Binary::ChromeHeadlessShell ? Binary::Chromedriver : Binary::ChromeHeadlessShell;
    file_put_contents($binaries->path($other), 'changed');
    removeDirectory(dirname($binaries->path($missing)));
    $this->tools->around('unzip', '');

    expect($this->installer->isInstalled($this->pin, Platform::LinuxArm64))->toBeFalse();

    $this->installer->install($this->pin, Platform::LinuxArm64);

    expect($this->installer->isInstalled($this->pin, Platform::LinuxArm64))->toBeTrue()
        ->and($this->tools->calls('curl'))->toHaveCount(2)
        ->and(file_get_contents($binaries->path($other)))->toBe($other === Binary::ChromeHeadlessShell ? BROWSER : CHROMEDRIVER)
        ->and(array_values(array_filter($this->tools->calls('unzip'), static fn (string $call): bool => str_contains($call, ' -d '))))->toHaveCount(2)
        ->and(file_get_contents($this->target.'/.sha256-pair'))->toBe(stampOf($this->pin, Platform::LinuxArm64));
})->with([Binary::ChromeHeadlessShell, Binary::Chromedriver]);

it('stops at a wrong SHA-256 of chromedriver after it unpacked the browser, and stamps no pair', function (): void {
    $changed = pinFor(VERSION, $this->archive, zipArchive($this->directory.'/other.zip', ['x' => ['other', 0o100644]]), Platform::LinuxArm64);

    expect(fn (): Binaries => $this->installer->install($changed, Platform::LinuxArm64))
        ->toThrow(checksumException(sprintf(
            'The SHA-256 of %s/chromedriver-linux-arm64.zip is %s, the pin expects %s. The archive is deleted and was not unpacked.',
            $this->target,
            hash_file('sha256', $this->chromedriverArchive),
            $changed->hash(Binary::Chromedriver, Platform::LinuxArm64),
        )))
        ->and(scandir($this->target))->toBe(['.', '..', '.lock', 'chrome-headless-shell-linux-arm64', 'chrome-headless-shell-linux-arm64.zip'])
        ->and($this->installer->isInstalled($changed, Platform::LinuxArm64))->toBeFalse();
});

it('keeps the browser archive when chromedriver fails, and downloads only chromedriver on the next run', function (): void {
    // The fake curl answers each URL with 503; the browser comes from a restored archive.
    $this->tools->respondWith(503);
    mkdir($this->target, 0o755, true);
    copy($this->archive, $this->target.'/chrome-headless-shell-linux-arm64.zip');

    expect(fn (): Binaries => $this->installer->install($this->pin, Platform::LinuxArm64))->toThrow(downloadException('chromedriver-linux-arm64.zip answered with status 503.'))
        ->and(file_exists($this->target.'/chrome-headless-shell-linux-arm64.zip'))->toBeTrue()
        // The browser comes first and is unpacked; without a stamp, the pair is not installed.
        ->and(file_exists($this->target.'/chrome-headless-shell-linux-arm64'))->toBeTrue()
        ->and(file_exists($this->target.'/.sha256-pair'))->toBeFalse()
        ->and($this->installer->isInstalled($this->pin, Platform::LinuxArm64))->toBeFalse();

    $this->tools->respondWith(200);
    $this->installer->install($this->pin, Platform::LinuxArm64);

    expect($this->tools->calls('curl'))->toHaveCount(2)
        ->and($this->tools->calls('curl')[1])->toContain('/chromedriver-linux-arm64.zip')
        ->and($this->installer->isInstalled($this->pin, Platform::LinuxArm64))->toBeTrue();
});

it('refuses on musl before it reads the pin file', function (Closure $pinFile): void {
    $file = $pinFile->call($this);
    assert(is_string($file));
    $installer = new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: true);

    expect(fn (): Binaries => $installer->binaries($file, Platform::LinuxArm64))
        ->toThrow(installerException(Libc::REFUSAL))
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([
    'no pin file' => [onTestCase(fn (): string => $this->directory.'/missing')],
    'a pin file of release 1.x' => [onTestCase(function (): string {
        file_put_contents($this->directory.'/.chrome-version', json_encode(['version' => VERSION, 'sha256' => ['linux-arm64' => str_repeat('a', 64)]]));

        return $this->directory.'/.chrome-version';
    })],
]);

it('detects musl when the caller gives no value', function (): void {
    $commandLine = $this->tools->commandLine();

    expect(new Installer($commandLine, $this->directory.'/cache')->onMusl())->toBe(Libc::detect($commandLine))
        ->and(new Installer($commandLine, $this->directory.'/cache', musl: true)->onMusl())->toBeTrue()
        ->and(new Installer($commandLine, $this->directory.'/cache', musl: false)->onMusl())->toBeFalse();
});

it('puts the browser under the version and the platform', function (): void {
    $umask = umask(0o022);

    try {
        $binary = installBrowser($this->installer, $this->pin);
    } finally {
        umask($umask);
    }

    expect($binary)->toBe($this->target.'/chrome-headless-shell-linux-arm64/chrome-headless-shell')
        ->and(is_executable($binary))->toBeTrue()
        ->and(fileperms($this->target.'/chrome-headless-shell-linux-arm64/LICENSE') & 0o777)->toBe(0o644)
        ->and(fileperms($this->target) & 0o777)->toBe(0o755)
        ->and(fileperms($this->target.'/.sha256-pair') & 0o777)->toBe(0o644)
        ->and($this->installer->isInstalled($this->pin, Platform::LinuxArm64))->toBeTrue();
});

it('writes the stamp with mode 0644 also under a umask that takes more', function (): void {
    $umask = umask(0o077);

    try {
        $this->installer->install($this->pin, Platform::LinuxArm64);
    } finally {
        umask($umask);
    }

    expect(fileperms($this->target.'/.sha256-pair') & 0o777)->toBe(0o644);
});

it('writes each file beside its target, also from a working directory that it cannot write', function (): void {
    $directory = (string) getcwd();
    mkdir($this->directory.'/read-only', 0o555);
    chdir($this->directory.'/read-only');

    try {
        $this->installer->install($this->pin, Platform::LinuxArm64);
    } finally {
        chdir($directory);
    }

    expect($this->installer->isInstalled($this->pin, Platform::LinuxArm64))->toBeTrue()
        ->and(scandir($this->directory.'/read-only'))->toBe(['.', '..']);
})->skip(posix_geteuid() === 0, 'Root writes into a directory of mode 0555.');

it('asks curl for HTTPS only, without redirects, without a curlrc and without the headers', function (Binary $binary): void {
    $this->installer->install($this->pin, Platform::LinuxArm64);
    $archive = $binary->directory(Platform::LinuxArm64).'.zip';

    expect($this->tools->calls('curl'))->toHaveCount(2)
        ->and($this->tools->calls('curl')[$binary === Binary::ChromeHeadlessShell ? 0 : 1])
        ->toMatch('~\A'.preg_quote("-q --silent --show-error --proto =https --tlsv1.2 --connect-timeout 30 --speed-limit 1024 --speed-time 60 --max-filesize 524288000 --output {$this->target}/{$archive}.part-", '~').'[0-9a-f]{8}'.preg_quote(' --write-out %{http_code} https://storage.googleapis.com/chrome-for-testing-public/155.0.8059.39/linux-arm64/'.$archive, '~').'\z~')
        ->not()->toContain('--location')
        ->not()->toContain('--insecure')
        ->not()->toContain('--dump-header');
})->with([Binary::ChromeHeadlessShell, Binary::Chromedriver]);

it('downloads and unpacks nothing on a second run', function (): void {
    $binary = installBrowser($this->installer, $this->pin);
    touch($binary, 1_000_000);

    $this->installer->install($this->pin, Platform::LinuxArm64);
    clearstatcache();

    expect($this->tools->calls('curl'))->toHaveCount(2)
        ->and(filemtime($binary))->toBe(1_000_000);
});

it('stops and deletes the archive when its SHA-256 differs from the pin', function (): void {
    $changed = pinFor(VERSION, zipArchive($this->directory.'/other.zip', ['x' => ['other', 0o100644]]), $this->chromedriverArchive, Platform::LinuxArm64);

    // The download is checked before it takes the place of the archive.
    expect(fn (): Binaries => $this->installer->install($changed, Platform::LinuxArm64))
        ->toThrow(checksumException(sprintf(
            'The SHA-256 of %s/chrome-headless-shell-linux-arm64.zip is %s, the pin expects %s. The archive is deleted and was not unpacked.',
            $this->target,
            hash_file('sha256', $this->archive),
            $changed->hash(Binary::ChromeHeadlessShell, Platform::LinuxArm64),
        )))
        ->and(scandir($this->target))->toBe(['.', '..', '.lock']);
});

it('checks the SHA-256 of an archive that a cache restored before it unpacks it', function (Binary $binary): void {
    mkdir($this->target, 0o755, true);
    copy($this->archive, $this->target.'/chrome-headless-shell-linux-arm64.zip');
    copy($this->chromedriverArchive, $this->target.'/chromedriver-linux-arm64.zip');
    $changed = $this->target.'/'.$binary->directory(Platform::LinuxArm64).'.zip';
    $other = $binary === Binary::ChromeHeadlessShell ? 'chromedriver-linux-arm64.zip' : 'chrome-headless-shell-linux-arm64.zip';
    file_put_contents($changed, 'changed', FILE_APPEND);

    // The browser comes first: after its failure, the archive of chromedriver stays as it is;
    // before the failure of chromedriver, the browser is unpacked.
    $left = $binary === Binary::ChromeHeadlessShell ? [$other] : ['chrome-headless-shell-linux-arm64', $other];

    expect(fn (): Binaries => $this->installer->install($this->pin, Platform::LinuxArm64))
        ->toThrow(checksumException(sprintf('The SHA-256 of %s is', $changed)))
        ->and(scandir($this->target))->toBe(['.', '..', '.lock', ...$left])
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([Binary::ChromeHeadlessShell, Binary::Chromedriver]);

it('unpacks the archives that a cache restored, and downloads nothing', function (): void {
    mkdir($this->target, 0o755, true);
    copy($this->archive, $this->target.'/chrome-headless-shell-linux-arm64.zip');
    copy($this->chromedriverArchive, $this->target.'/chromedriver-linux-arm64.zip');

    $binaries = $this->installer->install($this->pin, Platform::LinuxArm64);

    expect(file_get_contents($binaries->path(Binary::ChromeHeadlessShell)))->toBe(BROWSER)
        ->and(file_get_contents($binaries->path(Binary::Chromedriver)))->toBe(CHROMEDRIVER)
        ->and($this->tools->calls('curl'))->toBe([]);
});

it('takes both binaries and a stamp of the pin as installed, and reads no archive', function (): void {
    $binaries = $this->installer->install($this->pin, Platform::LinuxArm64);
    // A changed archive, and then none: neither is read again.
    file_put_contents($this->target.'/chrome-headless-shell-linux-arm64.zip', 'changed', FILE_APPEND);
    file_put_contents($this->target.'/chromedriver-linux-arm64.zip', 'changed', FILE_APPEND);

    $again = $this->installer->install($this->pin, Platform::LinuxArm64);
    unlink($this->target.'/chrome-headless-shell-linux-arm64.zip');
    unlink($this->target.'/chromedriver-linux-arm64.zip');

    expect($again)->toEqual($binaries)
        ->and($this->installer->install($this->pin, Platform::LinuxArm64))->toEqual($binaries)
        ->and($this->tools->calls('curl'))->toHaveCount(2)
        ->and(file_exists($this->target.'/chrome-headless-shell-linux-arm64.zip'))->toBeFalse();
});

it('unpacks again when the pair came from other archives', function (string $stamp): void {
    $binaries = $this->installer->install($this->pin, Platform::LinuxArm64);
    file_put_contents($this->target.'/.sha256-pair', $stamp);
    file_put_contents($binaries->path(Binary::ChromeHeadlessShell), 'changed');
    file_put_contents($binaries->path(Binary::Chromedriver), 'changed');

    $this->installer->install($this->pin, Platform::LinuxArm64);

    expect(file_get_contents($binaries->path(Binary::ChromeHeadlessShell)))->toBe(BROWSER)
        ->and(file_get_contents($binaries->path(Binary::Chromedriver)))->toBe(CHROMEDRIVER)
        // Two patterns, not GLOB_BRACE: PHP 8.4 on musl has no such flag.
        ->and([...glob($this->target.'/.old-*') ?: [], ...glob($this->target.'/.unpack-*') ?: []])->toBe([])
        ->and(file_get_contents($this->target.'/.sha256-pair'))->toBe(stampOf($this->pin, Platform::LinuxArm64))
        ->and($this->tools->calls('curl'))->toHaveCount(2);
})->with([
    'another pair' => ['chrome-headless-shell '.str_repeat('0', 64)."\nchromedriver ".str_repeat('1', 64)."\n"],
    'no line of a binary' => [str_repeat('0', 64)],
]);

it('stops before it writes any entry when one entry leaves the target', function (Binary $binary, string $name): void {
    // The good entry comes first: a check per entry while writing would have written it.
    expect(installArchive($binary, [
        '{top}/{binary}' => [BROWSER, 0o100755],
        $name => ['evil', 0o100644],
    ])->bindTo($this))
        // The real zipinfo lists these names; the ListingTest of safe-unzip names the reason for each one.
        ->toThrow(installerException('Nothing was unpacked.', UnzipException::class))
        // The checked archive goes back to the cache. After the browser, chromedriver is not fetched.
        ->and(glob($this->target.'/*.zip'))->toBe(cachedArchives($this->target, $binary))
        ->and(file_exists($this->target.'/'.$binary->directory(Platform::LinuxArm64)))->toBeFalse()
        ->and(file_exists($this->directory.'/cache/evil'))->toBeFalse();
})->with([Binary::ChromeHeadlessShell, Binary::Chromedriver])->with([
    'parent' => '../evil',
    'parent in the middle' => '{top}/../../evil',
    'absolute' => '/tmp/evil',
    'backslash' => '..\\evil',
    'line break' => "{top}/a\nb",
]);

it('stops at a symbolic link', function (Binary $binary): void {
    $link = $binary->directory(Platform::LinuxArm64).'/link';

    expect(installArchive($binary, [
        '{top}/{binary}' => [BROWSER, 0o100755],
        '{top}/link' => ['/etc/passwd', 0o120777],
    ])->bindTo($this))
        ->toThrow(static function (InstallerException $exception) use ($link): void {
            $refused = $exception->getPrevious();

            expect($exception::class)->toBe(InstallerException::class)
                ->and($exception->getMessage())->toContain(sprintf('N5 An entry is neither a file nor a directory: the archive entry `%s` is a symbolic link. Nothing was unpacked.', $link))
                ->and($refused)->toBeInstanceOf(RefusedException::class)
                ->and($refused instanceof RefusedException ? $refused->reason : null)->toBe(Rule::EntryType);
        })
        ->and(glob($this->target.'/*.zip'))->toBe(cachedArchives($this->target, $binary))
        ->and(file_exists($this->target.'/'.$binary->directory(Platform::LinuxArm64)))->toBeFalse();
})->with([Binary::ChromeHeadlessShell, Binary::Chromedriver]);

it('never makes a file writable for group or others', function (Binary $binary): void {
    $path = installArchive($binary, ['{top}/{binary}' => [BROWSER, 0o100777]])->call($this);
    assert(is_string($path));

    expect(fileperms($path) & 0o777)->toBe(0o755);
})->with([Binary::ChromeHeadlessShell, Binary::Chromedriver]);

it('stops at an archive without the binary, and stamps no pair', function (Binary $binary): void {
    expect(installArchive($binary, ['{top}/readme' => ['x', 0o100644]])->bindTo($this))
        ->toThrow(installerException(sprintf('does not contain %s.', $binary->path(Platform::LinuxArm64))))
        ->and(glob($this->target.'/.unpack-*'))->toBe([])
        ->and(file_exists($this->target.'/.sha256-pair'))->toBeFalse();
})->with([Binary::ChromeHeadlessShell, Binary::Chromedriver]);

it('stops at a file that is no ZIP archive', function (): void {
    file_put_contents($this->directory.'/none.zip', 'no zip');
    $this->tools->serve(VERSION, Binary::ChromeHeadlessShell, Platform::LinuxArm64, $this->directory.'/none.zip');

    expect(fn (): Binaries => $this->installer->install(pinFor(VERSION, $this->directory.'/none.zip', $this->chromedriverArchive, Platform::LinuxArm64), Platform::LinuxArm64))
        ->toThrow(installerException('is no ZIP archive: zipinfo finds no end record.', InvalidArchiveException::class));
});

it('follows no redirect, names a missing version, and leaves no file', function (int|string $status, string $class, string $message): void {
    /** @var 'fail'|int $status */
    $this->tools->respondWith($status);
    $url = Download::url(VERSION, Binary::ChromeHeadlessShell, Platform::LinuxArm64);

    expect(fn (): Binaries => $this->installer->install($this->pin, Platform::LinuxArm64))
        // Pest ignores what the closure returns, so it checks with expect().
        ->toThrow(static function (InstallerException $exception) use ($class, $url, $message): void {
            expect($exception::class)->toBe($class)
                ->and($exception->getMessage())->toBe(sprintf($message, $url));
        })
        ->and(scandir($this->target))->toBe(['.', '..', '.lock']);
})->with([
    'redirect' => [302, DownloadException::class, '%s answered with status 302. The installer follows no redirect.'],
    // A retry does not help: a human fixes the version.
    'missing' => [404, InstallerException::class, '%s answered with status 404: version 155.0.8059.39 does not exist for linux-arm64. Check the version on https://googlechromelabs.github.io/chrome-for-testing/.'],
    // Only a 3xx is a redirect.
    'an error of the server' => [503, DownloadException::class, '%s answered with status 503.'],
    'curl fails' => ['fail', DownloadException::class, 'The download of %s stopped. `curl` stopped with exit code 6: curl: (6) Could not resolve host'],
]);

it('waits for another install in the same directory, and gives up after its timeout', function (): void {
    mkdir($this->target, 0o755, true);
    $held = new SplFileObject($this->target.'/.lock', 'c');
    $held->flock(LOCK_EX);

    $installer = new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: false, lockTimeout: 1);
    $start = microtime(true);

    // flock() locks an open file, so a second open file of the same process waits too.
    expect(fn (): Binaries => $installer->install($this->pin, Platform::LinuxArm64))
        ->toThrow(installerException(sprintf('Another install in %s held the lock for 1 seconds.', $this->target)))
        ->and(microtime(true) - $start)->toBeGreaterThanOrEqual(1.0)
        ->and($this->tools->calls('curl'))->toBe([]);

    $held->flock(LOCK_UN);

    expect(is_file(installBrowser($installer, $this->pin)))->toBeTrue();
});

it('pauses while it waits for the lock, and spends almost no CPU time', function (): void {
    mkdir($this->target, 0o755, true);
    $held = new SplFileObject($this->target.'/.lock', 'c');
    $held->flock(LOCK_EX);

    $this->pin->write($this->directory.'/.chrome-version');

    // In a new process, so that its CPU time is the one of the wait alone.
    [$output] = php(sprintf(
        '$installer = new %s(new %s(%s), %s, musl: false, lockTimeout: 1); $before = getrusage(); try { $installer->install(%s::read(%s), %s::LinuxArm64); } catch (%s $exception) { echo str_contains($exception->getMessage(), "held the lock") ? $exception::class : "other"; } $after = getrusage(); printf(" %%.3f", ($after["ru_utime.tv_sec"] + $after["ru_stime.tv_sec"] - $before["ru_utime.tv_sec"] - $before["ru_stime.tv_sec"]) + ($after["ru_utime.tv_usec"] + $after["ru_stime.tv_usec"] - $before["ru_utime.tv_usec"] - $before["ru_stime.tv_usec"]) / 1e6);',
        Installer::class,
        CommandLine::class,
        var_export($this->tools->commandLine()->environment(), true),
        var_export($this->directory.'/cache', true),
        Pin::class,
        var_export($this->directory.'/.chrome-version', true),
        Platform::class,
        InstallerException::class,
    ));
    $held->flock(LOCK_UN);
    [$class, $seconds] = explode(' ', $output) + ['', ''];

    expect($class)->toBe(InstallerException::class)
        ->and((float) $seconds)->toBeLessThan(0.5);
});

it('waits for the seconds of CDP_BROWSER_LOCK_TIMEOUT, and gives each tool those of CDP_BROWSER_TIMEOUT', function (): void {
    mkdir($this->target, 0o755, true);
    $held = new SplFileObject($this->target.'/.lock', 'c');
    $held->flock(LOCK_EX);

    $environment = ['CDP_BROWSER_PATH' => $this->directory.'/cache', Installer::LOCK_TIMEOUT => '1', Installer::TIMEOUT => '1'];
    $installer = Installer::fromEnvironment($environment, $this->tools->commandLine(timeout: 60), musl: false);
    $start = microtime(true);

    expect(fn (): Binaries => $installer->install($this->pin, Platform::LinuxArm64))
        ->toThrow(installerException(sprintf('Another install in %s held the lock for 1 seconds.', $this->target)))
        ->and(microtime(true) - $start)->toBeGreaterThanOrEqual(1.0)->toBeLessThan(5.0);

    $held->flock(LOCK_UN);
    $this->tools->add('curl', 'sleep(5);');

    expect(fn (): Binaries => $installer->install($this->pin, Platform::LinuxArm64))
        ->toThrow(downloadException('`curl` stopped: The process'))
        ->and(microtime(true) - $start)->toBeLessThan(5.0);
});

it('keeps the timeout of the tools, and waits for the lock, without the variables or with empty ones', function (array $environment): void {
    /** @var array<string, string> $environment */
    mkdir($this->target, 0o755, true);
    $installer = Installer::fromEnvironment(['CDP_BROWSER_PATH' => $this->directory.'/cache', ...$environment], $this->tools->commandLine(timeout: 1), musl: false);
    $this->tools->add('curl', 'sleep(5);');
    $failure = null;

    // The other process holds the lock for a second; an install that does not wait stops at once.
    php(sprintf(
        '$lock = fopen(%1$s, "c"); flock($lock, LOCK_EX); touch(%2$s); usleep(1_000_000);',
        var_export($this->target.'/.lock', true),
        var_export($this->directory.'/held', true),
    ), meanwhile: function () use ($installer, &$failure): void {
        $deadline = microtime(true) + 10;

        while (! is_file($this->directory.'/held')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The other process took no lock within 10 seconds.');
            }

            usleep(10_000);
        }

        try {
            $installer->install($this->pin, Platform::LinuxArm64);
        } catch (InstallerException $installerException) {
            $failure = $installerException;
        }
    });

    expect($failure)->toBeInstanceOf(DownloadException::class)
        ->and($failure?->getMessage())->toContain('exceeded the timeout of 1 seconds.');
})->with([
    'not set' => [[]],
    'empty' => [[Installer::TIMEOUT => '', Installer::LOCK_TIMEOUT => '']],
]);

it('takes a whole number of seconds above 0 for each timeout, and names any other value', function (string $value): void {
    // The example of each message is the default of its variable.
    foreach ([Installer::TIMEOUT => 900, Installer::LOCK_TIMEOUT => 600] as $variable => $example) {
        expect(fn (): Installer => Installer::fromEnvironment(['HOME' => '/home/me', $variable => $value], $this->tools->commandLine(), musl: false))
            ->toThrow(installerException(sprintf('%s `%s` is not a whole number of seconds above 0, such as %d.', $variable, $value, $example)));
    }
})->with([
    'the notation of a float' => '1e3',
    'zero' => '0',
    'negative' => '-5',
    'a sign' => '+5',
    'a space before' => ' 5',
    'a space after' => '5 ',
    'a leading zero' => '05',
    'a fraction' => '1.5',
    'a unit' => '15m',
    'above the largest integer' => '99999999999999999999',
]);

it('waits while another process holds the lock, then takes the archive of that process', function (): void {
    $pinFile = $this->directory.'/.chrome-version';
    $this->pin->write($pinFile);
    // The other process holds the lock while curl runs; 2.5 s leave time for the check under load.
    $this->tools->delay(2500);
    // Recorded, so that the check after the lock shows: only the other process unpacks.
    $this->tools->around('unzip', '');

    $binary = null;
    $held = null;

    [$other] = php(installInProcess($this->tools, $this->directory.'/cache', $pinFile), meanwhile: function () use (&$binary, &$held): void {
        $deadline = microtime(true) + 10;

        while ($this->tools->calls('curl') === []) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The other process started no curl within 10 seconds.');
            }

            usleep(10_000);
        }

        // A second open file of the lock: flock() refuses it while the other process holds the lock.
        $probe = new SplFileObject($this->target.'/.lock', 'c');
        $held = ! $probe->flock(LOCK_EX | LOCK_NB);
        // Closes the file, and so frees a lock that the probe took.
        unset($probe);

        $binary = installBrowser($this->installer, $this->pin);
    });

    expect($held)->toBeTrue()
        ->and($binary)->toBe($this->target.'/chrome-headless-shell-linux-arm64/chrome-headless-shell')
        ->and($other)->toBe($binary)
        ->and($this->tools->calls('curl'))->toHaveCount(2)
        ->and(array_filter($this->tools->calls('unzip'), static fn (string $call): bool => str_contains($call, ' -d ')))->toHaveCount(2);
});

/**
 * PHP code for a new process: it installs the pair of the pin file and prints the path of the
 * browser.
 */
function installInProcess(FakeTools $tools, string $cache, string $pinFile): string
{
    return sprintf(
        'echo new %s(new %s(%s), %s, musl: false)->install(%s::read(%s), %s::LinuxArm64)->path(%s::ChromeHeadlessShell);',
        Installer::class,
        CommandLine::class,
        var_export($tools->commandLine()->environment(), true),
        var_export($cache, true),
        Pin::class,
        var_export($pinFile, true),
        Platform::class,
        Binary::class,
    );
}

/**
 * The entries of the directory of an installed pair, with the files of the test.
 *
 * @param  list<string>  $more
 * @return list<string>
 */
function pairEntries(array $more = []): array
{
    $entries = ['.', '..', '.lock', '.sha256-pair', 'chrome-headless-shell-linux-arm64', 'chrome-headless-shell-linux-arm64.zip', 'chromedriver-linux-arm64', 'chromedriver-linux-arm64.zip', ...$more];
    sort($entries);

    return $entries;
}

it('gives a whole pair to two runs that the lock does not exclude', function (): void {
    // Each run opens the lock through a link. Turned to another file, the link gives the second
    // run a lock of its own, as flock() on a bind mount of Docker Desktop does.
    mkdir($this->target, 0o755, true);
    symlink('lock-a', $this->target.'/.lock');
    $pinFile = $this->directory.'/.chrome-version';
    $this->pin->write($pinFile);
    // The first run has written its download; its curl answers 2.5 s later.
    $this->tools->delay(2500);
    $binary = null;

    [$first] = php(installInProcess($this->tools, $this->directory.'/cache', $pinFile), meanwhile: function () use (&$binary): void {
        $deadline = microtime(true) + 10;

        while (glob($this->target.'/*.zip.part*') === []) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The other process wrote no download within 10 seconds.');
            }

            usleep(10_000);
        }

        unlink($this->target.'/.lock');
        symlink('lock-b', $this->target.'/.lock');
        $this->tools->delay(0);
        $binary = installBrowser($this->installer, $this->pin);
    });

    // The first run downloads the browser; the second downloads both; the first then finds the
    // archive of chromedriver in the cache.
    expect($binary)->toBe($this->target.'/chrome-headless-shell-linux-arm64/chrome-headless-shell')
        ->and($first)->toBe($binary)
        ->and($this->tools->calls('curl'))->toHaveCount(3)
        ->and(file_get_contents((string) $binary))->toBe(BROWSER)
        ->and(hash_file('sha256', $this->target.'/chrome-headless-shell-linux-arm64.zip'))->toBe($this->pin->hash(Binary::ChromeHeadlessShell, Platform::LinuxArm64))
        ->and(file_get_contents($this->target.'/.sha256-pair'))->toBe(stampOf($this->pin, Platform::LinuxArm64))
        ->and(scandir($this->target))->toBe(pairEntries(['lock-a', 'lock-b']));
});

it('keeps the pair that another run moved in while this one unpacked', function (): void {
    mkdir($this->target, 0o755, true);
    symlink('lock-a', $this->target.'/.lock');
    $pinFile = $this->directory.'/.chrome-version';
    $this->pin->write($pinFile);
    // The first call of unzip waits while the file `hold` exists: the first run is past its check
    // of the stamp, and has not unpacked yet. PHP keeps a stat() that found the file. It goes on
    // after the 60 s of the timeout of the fakes, or when the install is gone (parent 1), so
    // that no fake outlives a stopped test.
    touch($this->directory.'/tools/hold');
    $this->tools->around('unzip', 'if (is_file($root."/hold") && @mkdir($root."/held")) { $deadline = microtime(true) + 60; while (is_file($root."/hold") && microtime(true) < $deadline && posix_getppid() !== 1) { usleep(10_000); clearstatcache(); } }');
    $binary = null;
    $tree = null;
    $stamp = null;

    [$first] = php(installInProcess($this->tools, $this->directory.'/cache', $pinFile), meanwhile: function () use (&$binary, &$tree, &$stamp): void {
        try {
            $deadline = microtime(true) + 10;

            while (! is_dir($this->directory.'/tools/held')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The other process started no unzip within 10 seconds.');
                }

                usleep(10_000);
            }

            unlink($this->target.'/.lock');
            symlink('lock-b', $this->target.'/.lock');
            $binary = installBrowser($this->installer, $this->pin);
            $tree = fileinode(dirname($binary));
            $stamp = fileinode($this->target.'/.sha256-pair');
        } finally {
            unlink($this->directory.'/tools/hold');
        }
    });
    clearstatcache();

    expect($first)->toBe($binary)
        // The tree of the second run, which a test may run already, stays where it is.
        ->and(fileinode(dirname((string) $binary)))->toBe($tree)
        ->and(file_get_contents((string) $binary))->toBe(BROWSER)
        // The first run writes the stamp again, as a new file.
        ->and(fileinode($this->target.'/.sha256-pair'))->not()->toBe($stamp)
        ->and(file_get_contents($this->target.'/.sha256-pair'))->toBe(stampOf($this->pin, Platform::LinuxArm64))
        ->and(scandir($this->target))->toBe(pairEntries(['lock-a', 'lock-b']));
});

// A test of the end state. Which run moves its tree first depends on the scheduler: a rename that
// meets the tree of the other run is rare here, and no step of the installer makes it certain.
it('leaves a whole pair after two runs that unpack at the same time', function (): void {
    mkdir($this->target, 0o755, true);
    symlink('lock-a', $this->target.'/.lock');
    $pinFile = $this->directory.'/.chrome-version';
    $this->pin->write($pinFile);
    // After it unpacked, each run waits for the other one: both then move their tree in at once.
    $this->tools->around('unzip', '', 'if (in_array("-d", $argv, true)) { touch($root."/unpacked-".getmypid()); $deadline = microtime(true) + 10; while (count(glob($root."/unpacked-*") ?: []) < 2 && microtime(true) < $deadline) { usleep(1000); } }');
    $binary = null;

    [$first] = php(installInProcess($this->tools, $this->directory.'/cache', $pinFile), meanwhile: function () use (&$binary): void {
        $deadline = microtime(true) + 10;

        while ($this->tools->calls('curl') === []) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('The other process started no curl within 10 seconds.');
            }

            usleep(10_000);
        }

        unlink($this->target.'/.lock');
        symlink('lock-b', $this->target.'/.lock');
        $binary = installBrowser($this->installer, $this->pin);
    });

    // Each run unpacks both archives.
    expect($first)->toBe($binary)
        ->and($binary)->toBe($this->target.'/chrome-headless-shell-linux-arm64/chrome-headless-shell')
        ->and(glob($this->directory.'/tools/unpacked-*'))->toHaveCount(4)
        ->and(file_get_contents((string) $binary))->toBe(BROWSER)
        ->and(file_get_contents($this->target.'/chromedriver-linux-arm64/chromedriver'))->toBe(CHROMEDRIVER)
        ->and(file_get_contents($this->target.'/.sha256-pair'))->toBe(stampOf($this->pin, Platform::LinuxArm64))
        ->and(scandir($this->target))->toBe(pairEntries(['lock-a', 'lock-b']));
});

it('unpacks the archive that it checked, not one that another run put at its path', function (bool $restored): void {
    mkdir($this->target, 0o755, true);
    $archive = $this->target.'/chrome-headless-shell-linux-arm64.zip';

    if ($restored) {
        copy($this->archive, $archive);
    }

    $pinFile = $this->directory.'/.chrome-version';
    $this->pin->write($pinFile);
    $other = zipArchive($this->directory.'/other.zip', [
        'chrome-headless-shell-linux-arm64/chrome-headless-shell' => ['other', 0o100755],
        'chrome-headless-shell-linux-arm64/icudtl.dat' => ['data', 0o100644],
    ]);
    // The first call of zipinfo waits while the file `hold` exists: the install has checked the
    // archive and reads it next. The fake gives up as the one of unzip above does.
    touch($this->directory.'/tools/hold');
    $this->tools->around('zipinfo', 'if (is_file($root."/hold") && @mkdir($root."/held")) { $deadline = microtime(true) + 60; while (is_file($root."/hold") && microtime(true) < $deadline && posix_getppid() !== 1) { usleep(10_000); clearstatcache(); } }');

    [$binary] = php(installInProcess($this->tools, $this->directory.'/cache', $pinFile), meanwhile: function () use ($other, $archive): void {
        try {
            $deadline = microtime(true) + 10;

            while (! is_dir($this->directory.'/tools/held')) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The other process started no zipinfo within 10 seconds.');
                }

                usleep(10_000);
            }

            // Another run without a working lock, with a pin of other bytes for the same version.
            rename($other, $archive);
        } finally {
            unlink($this->directory.'/tools/hold');
        }
    });

    expect($binary)->toBe($this->target.'/chrome-headless-shell-linux-arm64/chrome-headless-shell')
        ->and(file_get_contents($binary))->toBe(BROWSER)
        // The checked archive takes the place of the other one in the cache.
        ->and(hash_file('sha256', $archive))->toBe($this->pin->hash(Binary::ChromeHeadlessShell, Platform::LinuxArm64))
        ->and(file_get_contents($this->target.'/.sha256-pair'))->toBe(stampOf($this->pin, Platform::LinuxArm64))
        ->and($this->tools->calls('curl'))->toHaveCount($restored ? 1 : 2)
        ->and(scandir($this->target))->toBe(pairEntries());
})->with([
    'an archive that a cache restored' => [true],
    'a download' => [false],
]);

it('gives an archive that a cache restored the time of the install', function (): void {
    mkdir($this->target, 0o755, true);
    $archive = $this->target.'/chrome-headless-shell-linux-arm64.zip';
    copy($this->archive, $archive);
    copy($this->chromedriverArchive, $this->target.'/chromedriver-linux-arm64.zip');
    touch($archive, 1_000_000);
    $start = time();

    $this->installer->install($this->pin, Platform::LinuxArm64);
    clearstatcache();

    expect(filemtime($archive))->toBeGreaterThanOrEqual($start)
        ->and($this->tools->calls('curl'))->toBe([]);
});

it('checks an empty archive that a cache restored, and deletes it with exit code 65', function (): void {
    mkdir($this->target, 0o755, true);
    $archive = $this->target.'/chrome-headless-shell-linux-arm64.zip';
    touch($archive);

    expect(fn (): mixed => underErrorExceptions(fn (): Binaries => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(checksumException(sprintf(
            'The SHA-256 of %s is %s, the pin expects %s. The archive is deleted and was not unpacked.',
            $archive,
            hash('sha256', ''),
            $this->pin->hash(Binary::ChromeHeadlessShell, Platform::LinuxArm64),
        )))
        ->and(scandir($this->target))->toBe(['.', '..', '.lock'])
        ->and($this->tools->calls('curl'))->toBe([]);
});

it('names an archive that it cannot read', function (): void {
    mkdir($this->target, 0o755, true);
    $archive = $this->target.'/chrome-headless-shell-linux-arm64.zip';
    copy($this->archive, $archive);
    chmod($archive, 0o000);

    expect(fn (): mixed => underErrorExceptions(fn (): Binaries => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('The archive %s could not be read.', $archive)));
})->skip(posix_geteuid() === 0, 'Root reads a file of mode 0000.');

it('frees the lock when the install is killed, also while one of its tools runs', function (): void {
    // curl reports the install that started it, then runs on.
    $this->tools->add('curl', 'file_put_contents($root."/install.pid", (string) posix_getppid()); file_put_contents($root."/curl.pid", (string) getmypid()); sleep(20);');
    $install = new Process([PHP_BINARY, '-r', sprintf(
        'require %s; new %s(new %s(%s), %s, musl: false)->install(%s::read(%s), %s::LinuxArm64);',
        var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true),
        Installer::class,
        CommandLine::class,
        var_export($this->tools->commandLine()->environment(), true),
        var_export($this->directory.'/cache', true),
        Pin::class,
        var_export($this->directory.'/.chrome-version', true),
        Platform::class,
    )]);
    $this->pin->write($this->directory.'/.chrome-version');
    $install->start();
    $deadline = microtime(true) + 10;

    while (! is_file($this->directory.'/tools/curl.pid')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('The install started no curl within 10 seconds.');
        }

        usleep(10_000);
    }

    usleep(50_000);
    posix_kill((int) file_get_contents($this->directory.'/tools/install.pid'), 9);

    while ($install->isRunning()) {
        usleep(10_000);
    }

    $lock = new SplFileObject($this->target.'/.lock', 'c');
    $free = $lock->flock(LOCK_EX | LOCK_NB);
    posix_kill((int) file_get_contents($this->directory.'/tools/curl.pid'), 9);

    expect($free)->toBeTrue();
});

it('gives its timeout to zipinfo and unzip', function (string $tool): void {
    // The archive is in the cache, so no curl runs under the timeout: on a busy machine, the fake
    // curl alone can take a second.
    mkdir($this->target, 0o755, true);
    copy($this->archive, $this->target.'/chrome-headless-shell-linux-arm64.zip');
    $this->tools->add($tool, 'sleep(5);');
    $installer = new Installer($this->tools->commandLine(timeout: 1), $this->directory.'/cache', musl: false);

    expect(fn (): Binaries => $installer->install($this->pin, Platform::LinuxArm64))
        ->toThrow(installerException(sprintf('`%s` stopped: it exceeded the timeout of 1 seconds.', $tool), ToolException::class));
})->with(['zipinfo', 'unzip']);

it('stops at a relative cache directory when the working directory is gone', function (): void {
    $directory = (string) getcwd();
    mkdir($this->directory.'/gone');
    chdir($this->directory.'/gone');
    rmdir($this->directory.'/gone');

    try {
        expect(fn (): string => Installer::cache(['CDP_BROWSER_PATH' => 'cache']))
            ->toThrow(installerException('CDP_BROWSER_PATH `cache` is relative, and the working directory is gone.'));
    } finally {
        chdir($directory);
    }
});

it('takes the cache directory from the environment', function (array $environment, string $cache): void {
    /** @var array<string, string> $environment */
    expect(Installer::cache($environment))->toBe($cache);
})->with([
    'override' => [['CDP_BROWSER_PATH' => '/repo/.cache/browser/', 'XDG_CACHE_HOME' => '/xdg', 'HOME' => '/home/me'], '/repo/.cache/browser'],
    'XDG' => [['XDG_CACHE_HOME' => '/xdg', 'HOME' => '/home/me'], '/xdg/cdp-browser'],
    'home' => [['HOME' => '/home/me'], '/home/me/.cache/cdp-browser'],
    'empty values' => [['CDP_BROWSER_PATH' => '', 'XDG_CACHE_HOME' => '', 'HOME' => '/home/me'], '/home/me/.cache/cdp-browser'],
    // Relative, it would depend on the working directory, and `-x` would read as an option.
    'relative' => [['CDP_BROWSER_PATH' => '-x/browser'], getcwd().'/-x/browser'],
    'dot and parent' => [['CDP_BROWSER_PATH' => '/repo/./x/../.cache//browser'], '/repo/.cache/browser'],
    // The suite may run in a directory of the root, such as /work.
    'relative with a parent' => [['CDP_BROWSER_PATH' => '../browser'], rtrim(dirname((string) getcwd()), '/').'/browser'],
    'XDG with a parent' => [['XDG_CACHE_HOME' => '/xdg/../cache', 'HOME' => '/home/me'], '/cache/cdp-browser'],
    'XDG at the root' => [['XDG_CACHE_HOME' => '/', 'HOME' => '/home/me'], '/cdp-browser'],
    'home at the root' => [['HOME' => '/'], '/.cache/cdp-browser'],
    // The XDG Base Directory Specification: a relative path is invalid and ignored.
    'relative XDG' => [['XDG_CACHE_HOME' => 'xdg', 'HOME' => '/home/me'], '/home/me/.cache/cdp-browser'],
    'tilde in XDG' => [['XDG_CACHE_HOME' => '~/.cache', 'HOME' => '/home/me'], '/home/me/.cache/cdp-browser'],
]);

it('needs a cache directory', function (array $environment): void {
    /** @var array<string, string> $environment */
    expect(fn (): string => Installer::cache($environment))
        ->toThrow(installerException('Neither CDP_BROWSER_PATH, an absolute XDG_CACHE_HOME nor HOME is set, so there is no cache directory.'));
})->with([
    'nothing' => [[]],
    'a relative XDG' => [['XDG_CACHE_HOME' => 'xdg']],
]);

it('stops at the root as the cache, and at a `~` that no shell expanded', function (array $environment, string $message): void {
    /** @var array<string, string> $environment */
    expect(fn (): string => Installer::cache($environment))->toThrow(installerException($message));
})->with([
    'root' => [['CDP_BROWSER_PATH' => '/'], 'CDP_BROWSER_PATH `/` is the root directory. Use a separate directory for the cache.'],
    'parent of the root' => [['CDP_BROWSER_PATH' => '/tmp/../..'], 'CDP_BROWSER_PATH `/tmp/../..` is the root directory.'],
    'tilde' => [['CDP_BROWSER_PATH' => '~/.cache/browser'], 'CDP_BROWSER_PATH `~/.cache/browser` starts with `~`, which only a shell expands. Use the full path.'],
    'tilde in HOME' => [['HOME' => '~'], 'HOME `~` starts with `~`'],
]);

it('stops at a cache directory that unzip reads as a pattern, before it downloads', function (string $name): void {
    $cache = $this->directory.'/'.$name;
    $message = sprintf('The cache directory %s/cdp-browser holds `*`, `?`, `[`, `]`, `\` or a line break, which unzip reads as a pattern or zipinfo prints as two lines. Use a path without them.', $cache);

    expect(fn (): Binaries => Installer::fromEnvironment(['CDP_BROWSER_PATH' => $cache.'/cdp-browser'], $this->tools->commandLine(), musl: false)->install($this->pin, Platform::LinuxArm64))
        ->toThrow(installerException($message))
        ->and(fn (): string => Installer::cache(['XDG_CACHE_HOME' => $cache, 'HOME' => '/home/me']))->toThrow(installerException($message))
        ->and(fn (): string => Installer::cache(['HOME' => $cache]))->toThrow(installerException(sprintf('The cache directory %s/.cache/cdp-browser holds', $cache)))
        ->and(fn (): Installer => new Installer($this->tools->commandLine(), $cache.'/cdp-browser'))->toThrow(installerException($message))
        ->and($this->tools->calls('curl'))->toBe([]);
})->with(['cache[1]', 'cache*', 'cache?', 'cache]', 'cache\\x', "cache\nx"]);

it('stops at a cache directory behind a symbolic link to such a name, before it downloads', function (): void {
    mkdir($this->directory.'/real[1]');
    symlink($this->directory.'/real[1]', $this->directory.'/link');
    // safe-unzip resolves the path, as /var to /private/var on macOS.
    $message = sprintf('The cache directory %s/real[1]/cache/cdp-browser holds `*`', realpath($this->directory));

    expect(fn (): Binaries => Installer::fromEnvironment(['CDP_BROWSER_PATH' => $this->directory.'/link/cache/cdp-browser'], $this->tools->commandLine(), musl: false)->install($this->pin, Platform::LinuxArm64))
        ->toThrow(installerException($message))
        ->and(fn (): Installer => new Installer($this->tools->commandLine(), $this->directory.'/link/cache/cdp-browser'))->toThrow(installerException($message))
        ->and($this->tools->calls('curl'))->toBe([]);
});

it('checks a cache directory outside of open_basedir, where realpath() resolves no part', function (): void {
    // Under an error handler as Laravel's, and with a limit of time, so that a loop ends.
    $code = sprintf(
        '%s echo %s::cache(["CDP_BROWSER_PATH" => "/srv/missing/cache"]), "\n"; try { %2$s::cache(["CDP_BROWSER_PATH" => "/srv/missing/cache[1]"]); } catch (%s $exception) { echo $exception->getMessage(); }',
        ERROR_EXCEPTIONS,
        Installer::class,
        InstallerException::class,
    );

    expect(php($code, settings: ['-d', 'open_basedir='.dirname(__DIR__, 2), '-d', 'max_execution_time=5']))->toBe([
        "/srv/missing/cache\nThe cache directory /srv/missing/cache[1] holds `*`, `?`, `[`, `]`, `\\` or a line break, which unzip reads as a pattern or zipinfo prints as two lines. Use a path without them.",
        0,
    ]);
});

it('takes from the constructor only an absolute cache directory that is not the root, and resolves it', function (): void {
    expect(fn (): Installer => new Installer(new CommandLine, 'cache'))
        ->toThrow(installerException('The cache directory `cache` is not an absolute path. Use the full path.'))
        ->and(fn (): Installer => new Installer(new CommandLine, '/'))
        ->toThrow(installerException('The cache directory `/` is the root directory. Use a separate directory for the cache.'))
        ->and(fn (): Installer => new Installer(new CommandLine, '/srv/..'))
        ->toThrow(installerException('The cache directory `/srv/..` is the root directory.'))
        ->and(new Installer(new CommandLine, '/srv/./x/../cache/')->paths($this->pin, Platform::LinuxArm64)->path(Binary::Chromedriver))
        ->toBe('/srv/cache/155.0.8059.39/linux-arm64/chromedriver-linux-arm64/chromedriver');
});

/**
 * An install in a new process. The installer calls `rename()` without a
 * namespace, so a function of its namespace records each call; with `$stop`, the process ends
 * before the rename of the stamp, as at SIGKILL.
 *
 * @return list<array{string, string}> each rename, from and to
 */
function renames(string $directory, Pin $pin, CommandLine $commandLine, bool $stop): array
{
    $log = $directory.'/renames';
    $rename = $directory.'/rename.php';
    file_put_contents($rename, sprintf(<<<'PHP'
        <?php

        namespace SorgeIt\CdpBrowserInstaller;

        function rename(string $from, string $to): bool
        {
            file_put_contents(%s, $from."\t".$to."\n", FILE_APPEND);

            if (%s && basename($to) === '.sha256-pair') {
                exit(9);
            }

            return \rename($from, $to);
        }
        PHP, var_export($log, true), var_export($stop, true)));
    $pin->write($directory.'/.chrome-version');

    [$output, $exitCode] = php(sprintf(
        'require %s; $installer = new %s(new %s(%s), %s, musl: false); $installer->install(%s::read(%s), %s::LinuxArm64);',
        var_export($rename, true),
        Installer::class,
        CommandLine::class,
        var_export($commandLine->environment(), true),
        var_export($directory.'/cache', true),
        Pin::class,
        var_export($directory.'/.chrome-version', true),
        Platform::class,
    ));

    if ($exitCode !== ($stop ? 9 : 0)) {
        throw new RuntimeException(sprintf('The install ended with exit code %d: %s', $exitCode, $output));
    }

    return array_map(static fn (string $line): array => explode("\t", $line, 2) + ['', ''], file($log, FILE_IGNORE_NEW_LINES) ?: []);
}

it('writes the stamp under a name of its own for each run, a dot and 8 hex digits after its target', function (): void {
    renames($this->directory, $this->pin, $this->tools->commandLine(), stop: false);
    // Without the stamp, the second run unpacks the archive again and writes a new stamp.
    unlink($this->target.'/.sha256-pair');
    $written = array_values(array_filter(renames($this->directory, $this->pin, $this->tools->commandLine(), stop: false), fn (array $rename): bool => $rename[1] === $this->target.'/.sha256-pair'));
    $name = '~\A'.preg_quote($this->target.'/.sha256-pair.', '~').'[0-9a-f]{8}\z~';

    expect($written)->toHaveCount(2)
        ->and($written[0][0])->toMatch($name)
        ->and($written[1][0])->toMatch($name)
        ->not()->toBe($written[0][0]);
});

it('removes the stamp that a run left when it stopped before the rename', function (): void {
    $renames = renames($this->directory, $this->pin, $this->tools->commandLine(), stop: true);
    [$left] = end($renames) ?: ['', ''];

    expect(is_file($left))->toBeTrue()
        ->and(dirname($left))->toBe($this->target);

    touch($left, time() - 121);
    $this->installer->install($this->pin, Platform::LinuxArm64);

    expect(file_exists($left))->toBeFalse()
        ->and($this->installer->isInstalled($this->pin, Platform::LinuxArm64))->toBeTrue();
});
