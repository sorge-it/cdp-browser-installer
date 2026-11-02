<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Files;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;

/*
 * Steps of the file system that fail; each message names the path. A path that a file or a
 * directory takes fails for root too, a read-only directory does not.
 */

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->archive = browserArchive($this->directory.'/source.zip', Platform::LinuxArm64);
    $this->pin = pinFor('155.0.8059.39', $this->archive);
    $this->tools = new FakeTools($this->directory.'/tools');
    $this->tools->serve('155.0.8059.39', Platform::LinuxArm64, $this->archive);

    $this->installer = new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: false);
    $this->target = $this->directory.'/cache/155.0.8059.39/linux-arm64';
});

it('names a cache directory that it cannot create below a file', function (): void {
    touch($this->directory.'/cache');

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('The directory %s could not be created.', $this->target)));
});

it('names a cache directory that it cannot create in a read-only directory', function (): void {
    mkdir($this->directory.'/cache', 0o555);

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('The directory %s could not be created.', $this->target)));
})->skip(posix_geteuid() === 0, 'Root writes into a directory of mode 0555.');

it('names a lock file that it cannot open', function (string $kind, string $previous): void {
    /** @var class-string<Throwable> $previous */
    if ($kind === 'directory') {
        mkdir($this->target.'/.lock', 0o755, true);
    } elseif (posix_geteuid() === 0) {
        $this->markTestSkipped('Root opens a file of mode 0000.');
    } else {
        mkdir($this->target, 0o755, true);
        touch($this->target.'/.lock');
        chmod($this->target.'/.lock', 0o000);
    }

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('The lock file %s/.lock could not be opened.', $this->target), $previous))
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([
    'a directory' => ['directory', LogicException::class],
    'a file without access' => ['file', RuntimeException::class],
]);

it('names a checked download that it cannot put into the cache, and removes it', function (): void {
    // After the unpack, the archive path holds a directory, as if another run had put it there.
    $archive = $this->target.'/chrome-headless-shell-linux-arm64.zip';
    $this->tools->around('unzip', '', sprintf('if (in_array("-d", $argv, true)) { @mkdir(%s); }', var_export($archive, true)));

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        // Pest ignores what the closure returns, so it checks with expect().
        ->toThrow(static function (InstallerException $exception) use ($archive): void {
            expect($exception::class)->toBe(InstallerException::class)
                ->and($exception->getMessage())->toMatch('~\A'.preg_quote($archive, '~').'\.part-[0-9a-f]{8} could not be moved to '.preg_quote($archive, '~').'\.\z~');
        })
        // Without a stamp, the next run unpacks again.
        ->and(scandir($this->target))->toBe(['.', '..', '.lock', 'chrome-headless-shell-linux-arm64', 'chrome-headless-shell-linux-arm64.zip']);
});

it('names something at the archive path that it cannot take, and downloads nothing', function (): void {
    $archive = $this->target.'/chrome-headless-shell-linux-arm64.zip';
    mkdir($archive, 0o755, true);

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(static function (InstallerException $exception) use ($archive): void {
            expect($exception::class)->toBe(InstallerException::class)
                ->and($exception->getMessage())->toMatch('~\A'.preg_quote($archive, '~').' could not be moved to '.preg_quote($archive, '~').'\.part-[0-9a-f]{8}\.\z~');
        })
        ->and($this->tools->calls('curl'))->toBe([])
        ->and(scandir($this->target))->toBe(['.', '..', '.lock', 'chrome-headless-shell-linux-arm64.zip']);
});

it('names a stamp that it cannot write, and leaves no new file', function (): void {
    mkdir($this->target.'/.sha256', 0o755, true);

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('The stamp in %s could not be written.', $this->target)))
        ->and(glob($this->target.'/.sha256.*'))->toBe([]);
});

it('names a stale stamp that it cannot remove, and keeps the browser', function (): void {
    $binary = $this->installer->install($this->pin, Platform::LinuxArm64);
    file_put_contents($this->target.'/.sha256', 'stale');
    file_put_contents($binary, 'old');
    // unzip makes the directory read-only after it unpacked; rm makes it writable again to clear up.
    $this->tools->add('unzip', sprintf('$process = proc_open([$root."/system/unzip", ...array_slice($argv, 1)], [1 => STDOUT, 2 => STDERR], $pipes); $code = proc_close($process); chmod(%s, 0o555); exit($code);', var_export($this->target, true)));
    $this->tools->add('rm', sprintf('chmod(%s, 0o755); $process = proc_open([$root."/system/rm", ...array_slice($argv, 1)], [1 => STDOUT, 2 => STDERR], $pipes); exit(proc_close($process));', var_export($this->target, true)));

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('The stamp in %s could not be removed.', $this->target)))
        ->and(file_get_contents($binary))->toBe('old')
        // Two patterns, not GLOB_BRACE: PHP 8.4 on musl has no such flag.
        ->and([...glob($this->target.'/.old-*') ?: [], ...glob($this->target.'/.unpack-*') ?: []])->toBe([]);
})->skip(posix_geteuid() === 0, 'Root removes a file from a directory of mode 0555.');

it('names a browser that it cannot move aside or into place, and keeps the old one', function (?string $old, string $message): void {
    $binary = $this->installer->install($this->pin, Platform::LinuxArm64);
    unlink($this->target.'/.sha256');

    if ($old === null) {
        removeDirectory(dirname($binary));
    } else {
        file_put_contents($binary, $old);
    }

    // As in the test of the stamp: unzip leaves the directory read-only, rm makes it writable.
    $this->tools->around('unzip', '', sprintf('chmod(%s, 0o555);', var_export($this->target, true)));
    $this->tools->around('rm', sprintf('chmod(%s, 0o755);', var_export($this->target, true)));

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(static function (InstallerException $exception) use ($message): void {
            expect($exception::class)->toBe(InstallerException::class)
                ->and($exception->getMessage())->toMatch($message);
        })
        ->and(is_file($binary) ? file_get_contents($binary) : null)->toBe($old)
        ->and([...glob($this->target.'/.old-*') ?: [], ...glob($this->target.'/.unpack-*') ?: []])->toBe([]);
})->with([
    'aside' => ['old', '~/linux-arm64/chrome-headless-shell-linux-arm64 could not be moved to \S+/linux-arm64/\.old-[0-9a-f]{8}\.\z~'],
    'into place' => [null, '~/\.unpack-[0-9a-f]{8}/chrome-headless-shell-linux-arm64 could not be moved to \S+/linux-arm64/chrome-headless-shell-linux-arm64\.\z~'],
])->skip(posix_geteuid() === 0, 'Root moves a directory out of a directory of mode 0555.');

it('names a file at the place of the browser, and leaves it', function (): void {
    $target = $this->target.'/chrome-headless-shell-linux-arm64';
    mkdir($this->target, 0o755, true);
    file_put_contents($target, 'a file');

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('could not be moved to %s.', $target)))
        ->and(file_get_contents($target))->toBe('a file')
        ->and(file_exists($this->target.'/.sha256'))->toBeFalse();
});

it('gives the path of an installed browser in a cache that it cannot write', function (): void {
    // As after `install --allow-root` in an image, and tests as another user.
    $binary = $this->installer->install($this->pin, Platform::LinuxArm64);
    $pinFile = $this->directory.'/.chrome-version';
    $this->pin->write($pinFile);
    $directories = [$this->directory.'/cache', dirname($this->target), $this->target];
    chmod($this->target.'/.lock', 0o444);

    foreach ($directories as $directory) {
        chmod($directory, 0o555);
    }

    try {
        expect($this->installer->browser($pinFile, Platform::LinuxArm64))->toBe($binary)
            ->and($this->tools->calls('curl'))->toHaveCount(1);
    } finally {
        foreach ($directories as $directory) {
            chmod($directory, 0o755);
        }
    }
})->skip(posix_geteuid() === 0, 'Root opens a file of mode 0444 for writing.');

it('removes what stopped runs left, after 120 seconds without a write, and keeps the rest', function (): void {
    mkdir($this->target, 0o755, true);
    $zip = $this->target.'/chrome-headless-shell-linux-arm64.zip';
    $left = [$zip.'.part-0123abcd', $this->target.'/.sha256.0123abcd', $this->target.'/.unpack-0123abcd', $this->target.'/.old-0123abcd'];
    // Each one younger, as a run that still writes it has; and names of other tools.
    $kept = [$zip.'.part-4567ef01', $this->target.'/.sha256.4567ef01', $this->target.'/.unpack-4567ef01', $this->target.'/.old-4567ef01',
        $this->target.'/other.zip.part-0123abcd', $this->target.'/.old-notes'];

    foreach ([...$left, ...$kept] as $path) {
        if (str_contains($path, '/.unpack-') || str_contains($path, '/.old-')) {
            mkdir($path.'/chrome-headless-shell-linux-arm64', 0o755, true);
            touch($path.'/chrome-headless-shell-linux-arm64/chrome-headless-shell');
        }

        touch($path, in_array($path, $left, true) ? time() - 121 : time() - 115);
    }

    // An installer whose lock timeout is 1 second: the age of a leftover does not follow it.
    new Installer($this->tools->commandLine(), $this->directory.'/cache', musl: false, lockTimeout: 1)->install($this->pin, Platform::LinuxArm64);

    expect(array_values(array_filter($left, file_exists(...))))->toBe([])
        ->and(array_values(array_filter($kept, file_exists(...))))->toBe($kept);
});

it('removes a leftover tree with rm -rf --, and goes on when another run removed a leftover first', function (): void {
    $tree = $this->target.'/.old-0123abcd';
    $stamp = $this->target.'/.sha256.0123abcd';
    mkdir($tree, 0o755, true);
    touch($tree, time() - 121);
    touch($stamp, time() - 121);
    // A run without a working lock removes the stamp while this one removes the tree: scandir()
    // lists `.old-` before `.sha256.`.
    $this->tools->around('rm', sprintf('if (in_array(%s, $argv, true)) { unlink(%s); }', var_export($tree, true), var_export($stamp, true)));

    underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64));

    expect($this->tools->calls('rm'))->toContain('-rf -- '.$tree)
        ->and(file_exists($tree))->toBeFalse()
        ->and(file_exists($stamp))->toBeFalse();
});

it('names a leftover that it cannot remove', function (): void {
    mkdir($this->target, 0o755, true);
    $left = $this->target.'/chrome-headless-shell-linux-arm64.zip.part-0123abcd';
    touch($left, time() - 121);
    touch($this->target.'/.lock');
    chmod($this->target, 0o555);

    expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
        ->toThrow(installerException(sprintf('The leftover %s could not be removed.', $left)));

    chmod($this->target, 0o755);
})->skip(posix_geteuid() === 0, 'Root removes a file from a directory of mode 0555.');

it('installs into a version directory that it cannot list', function (): void {
    // Write and search, no read: scandir() of the leftovers fails.
    mkdir($this->target, 0o755, true);
    chmod($this->target, 0o300);

    try {
        $binary = underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64));
    } finally {
        chmod($this->target, 0o755);
    }

    assert(is_string($binary));

    expect(is_executable($binary))->toBeTrue();
})->skip(posix_geteuid() === 0, 'Root lists a directory of mode 0300.');

it('unpacks again over a stamp that it cannot read', function (): void {
    $this->installer->install($this->pin, Platform::LinuxArm64);
    chmod($this->target.'/.sha256', 0o000);

    // A new stamp, with mode 0644, replaced the one it could not read.
    expect(underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))->toBeFile()
        ->and(fileperms($this->target.'/.sha256') & 0o777)->toBe(0o644)
        ->and(file_get_contents($this->target.'/.sha256'))->toBe($this->pin->hash(Platform::LinuxArm64));
})->skip(posix_geteuid() === 0, 'Root reads a file of mode 0000.');

it('keeps the error of the download when it cannot remove the download', function (): void {
    // curl writes part of the file, then the directory turns read-only.
    $this->tools->add('curl', sprintf('file_put_contents($argv[array_search("--output", $argv, true) + 1], "part"); chmod(%s, 0o555); echo "503";', var_export($this->target, true)));

    try {
        expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
            ->toThrow(downloadException('answered with status 503.'))
            ->and(glob($this->target.'/*.part-*'))->toHaveCount(1);
    } finally {
        chmod($this->target, 0o755);
    }
})->skip(posix_geteuid() === 0, 'Root removes a file from a directory of mode 0555.');

it('names an archive that a cache restored and that it cannot take', function (): void {
    $archive = $this->target.'/chrome-headless-shell-linux-arm64.zip';
    mkdir($this->target, 0o755, true);
    file_put_contents($archive, 'wrong');
    touch($this->target.'/.lock');
    chmod($this->target, 0o555);

    try {
        expect(fn (): mixed => underErrorExceptions(fn (): string => $this->installer->install($this->pin, Platform::LinuxArm64)))
            ->toThrow(static function (InstallerException $exception) use ($archive): void {
                expect($exception::class)->toBe(InstallerException::class)
                    ->and($exception->getMessage())->toMatch('~\A'.preg_quote($archive, '~').' could not be moved to '.preg_quote($archive, '~').'\.part-[0-9a-f]{8}\.\z~');
            })
            ->and(file_get_contents($archive))->toBe('wrong')
            ->and($this->tools->calls('curl'))->toBe([]);
    } finally {
        chmod($this->target, 0o755);
    }
})->skip(posix_geteuid() === 0, 'Root renames a file in a directory of mode 0555.');

it('removes the half-written file when the disk is full', function (): void {
    // With a file size limit of 0, `file_put_contents()` creates the file and fails on the write,
    // as on a full disk. SIGXFSZ is ignored, else it ends the process at the write.
    // Under an error handler as Laravel's, which a warning outside `@` stops.
    $code = sprintf(
        '%s pcntl_signal(SIGXFSZ, SIG_IGN); posix_setrlimit(POSIX_RLIMIT_FSIZE, 0, POSIX_RLIMIT_INFINITY); var_export(%s::write(%s, "x")); posix_setrlimit(POSIX_RLIMIT_FSIZE, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);',
        ERROR_EXCEPTIONS,
        Files::class,
        var_export($this->directory.'/stamp', true),
    );

    expect(php($code))->toBe(['false', 0])
        ->and(glob($this->directory.'/stamp*'))->toBe([]);
})->skip(! function_exists('pcntl_signal'), 'The test needs pcntl to ignore SIGXFSZ.');

it('suppresses the warning of a failed chmod and of the removal after it', function (): void {
    // Both calls fail as PHP's do, with a warning. Files calls them without a namespace, so these
    // functions of its namespace take their place; unlink() still removes the file.
    $functions = $this->directory.'/functions.php';
    file_put_contents($functions, <<<'PHP'
        <?php

        namespace SorgeIt\CdpBrowserInstaller;

        function chmod(string $file, int $mode): bool
        {
            trigger_error('chmod(): Operation not permitted', E_USER_WARNING);

            return false;
        }

        function unlink(string $file): bool
        {
            \unlink($file);
            trigger_error('unlink(): Operation not permitted', E_USER_WARNING);

            return false;
        }
        PHP);
    $code = sprintf('require %s; %s var_export(%s::write(%s, "x"));', var_export($functions, true), ERROR_EXCEPTIONS, Files::class, var_export($this->directory.'/stamp', true));

    expect(php($code))->toBe(['false', 0])
        ->and(glob($this->directory.'/stamp*'))->toBe([]);
});
