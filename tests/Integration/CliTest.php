<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use SorgeIt\CdpBrowserInstaller\CommandLine;
use SorgeIt\CdpBrowserInstaller\Console\Cli;
use SorgeIt\CdpBrowserInstaller\Console\PathCommand;
use SorgeIt\CdpBrowserInstaller\Console\PinCommand;
use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\Environment;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\SystemLibraries;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;
use SorgeIt\SafeUnzip\Exception\InvalidArchiveException;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->archive = browserArchive($this->directory.'/source.zip', Platform::LinuxX64);
    $this->pinFile = $this->directory.'/.chrome-version';
    pinFor('155.0.8059.39', $this->archive)->write($this->pinFile);
    $this->tools = new FakeTools($this->directory.'/tools');

    foreach (Platform::cases() as $platform) {
        $this->tools->serve('155.0.8059.39', $platform, $platform === Platform::LinuxX64 ? $this->archive : browserArchive($this->directory.'/'.$platform->value.'.zip', $platform));
    }
});

it('installs and prints the path', function (): void {
    $tester = $this->cli();

    $tester->run(['command' => 'install', '--pin-file' => $this->pinFile]);

    $installed = $tester->getDisplay();
    $tester->run(['command' => 'path', '--pin-file' => $this->pinFile]);
    $binary = $this->directory.'/cache/155.0.8059.39/linux64/chrome-headless-shell-linux64/chrome-headless-shell';
    $path = mb_trim($tester->getDisplay());

    $tester->run(['command' => 'install', '--pin-file' => $this->pinFile]);

    // The second install finds the browser and downloads nothing.
    expect($installed)->toBe(sprintf("Download %s\nchrome-headless-shell for linux64 is at %s.\n", Download::url('155.0.8059.39', Platform::LinuxX64), $binary))
        ->and($path)->toBe($binary)
        ->and($tester->getDisplay())->toBe(sprintf("chrome-headless-shell for linux64 is at %s.\n", $binary))
        ->and(is_executable($binary))->toBeTrue();
});

it('names the install command when the browser is missing', function (): void {
    expect(fn () => $this->cli()->run(['command' => 'path', '--pin-file' => $this->pinFile]))
        ->toThrow(installerException('chrome-headless-shell 155.0.8059.39 for linux64 is not installed. Run `cdp-browser install`.'));
});

it('pins the SHA-256 of every platform in a directory of its own, and leaves no temporary file', function (): void {
    $file = $this->directory.'/pinned';
    $this->tools->around('unzip', '');
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    $tester->execute(['version' => '155.0.8059.39', '--pin-file' => $file]);

    $directories = pinDirectories($this->tools);
    // safe-unzip resolves the path, as /var to /private/var on macOS.
    $run = '~ \S*/'.preg_quote(basename($directories[0] ?? 'none'), '~').'/';

    expect(Pin::read($file)->hash(Platform::MacArm64))->toBe(hash_file('sha256', $this->directory.'/mac-arm64.zip'))
        ->and($directories)->toHaveCount(1)
        ->and($this->tools->calls('curl'))->toHaveCount(4)
        ->each()->toMatch($run.'archive\.zip ~')
        ->and(array_values(array_filter($this->tools->calls('unzip'), static fn (string $call): bool => str_contains($call, ' -d '))))->toHaveCount(4)
        ->each()->toMatch($run.'tree/\S+\z~')
        ->and(array_filter($directories, file_exists(...)))->toBe([])
        ->and($tester->getDisplay())->toBe(implode('', array_map(static fn (Platform $platform): string => 'Download '.Download::url('155.0.8059.39', $platform)."\n", Platform::cases()))
            .sprintf("Wrote 155.0.8059.39 to %s. Review the change before you commit it.\n", $file));
});

it('unpacks the archive of each platform into an empty tree', function (): void {
    // Before each unpack: the names in the tree, beside the staging directory of safe-unzip.
    $this->tools->around('unzip', '$option = array_search("-d", $argv, true); if ($option !== false) { file_put_contents($root."/trees", implode(" ", preg_grep("~\A(?:\.\.?|\.safe-unzip-\w+)\z~", scandir(dirname($argv[$option + 1])) ?: [], PREG_GREP_INVERT))."\n", FILE_APPEND); }');
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    $tester->execute(['version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']);

    expect(file_get_contents($this->directory.'/tools/trees'))->toBe(str_repeat("\n", count(Platform::cases())));
});

it('checks the version before it downloads', function (string $version): void {
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    expect(fn (): int => $tester->execute(['version' => $version, '--pin-file' => $this->directory.'/pinned']))
        ->toThrow(installerException(sprintf('`%s` is not a Chrome for Testing version, such as 155.0.8059.39.', $version)))
        ->and($this->tools->calls('curl'))->toBe([])
        ->and($tester->getDisplay())->toBe('');
})->with([
    'a channel' => 'stable',
    'a path' => '155.0.8059.39/../../x',
]);

it('names a directory for the archives that it cannot create', function (): void {
    mkdir($this->directory.'/tmp', 0o555);
    // In a new process: PHP reads `sys_temp_dir` once.
    $code = sprintf(
        'try { new %s(new %s(fn (): %s => new %s(%s), 1000))->execute(["version" => "155.0.8059.39", "--pin-file" => %s]); } catch (%s $exception) { echo $exception->getMessage(); }',
        CommandTester::class,
        PinCommand::class,
        CommandLine::class,
        CommandLine::class,
        var_export($this->tools->commandLine()->environment(), true),
        var_export($this->directory.'/pinned', true),
        InstallerException::class,
    );

    [$output, $exitCode] = php($code, settings: ['-d', 'sys_temp_dir='.$this->directory.'/tmp']);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('~\A'.preg_quote(sprintf('The directory %s/tmp/cdp-browser-pin-', $this->directory), '~').'[0-9a-f]{16} could not be created\.\z~')
        ->and($this->tools->calls('curl'))->toBe([]);
})->skip(posix_geteuid() === 0, 'Root writes into a directory of mode 0555.');

it('refuses a character of the cache check in its temporary directory, before the first download', function (): void {
    mkdir($this->directory.'/tmp[1]');
    // In a new process: PHP reads `sys_temp_dir` once.
    $code = sprintf(
        'try { new %s(new %s(fn (): %s => new %s(%s), 1000))->execute(["version" => "155.0.8059.39", "--pin-file" => %s]); } catch (%s $exception) { echo $exception->getMessage(); }',
        CommandTester::class,
        PinCommand::class,
        CommandLine::class,
        CommandLine::class,
        var_export($this->tools->commandLine()->environment(), true),
        var_export($this->directory.'/pinned', true),
        InstallerException::class,
    );

    // unzip reads `[` in the path of the archive as a pattern.
    expect(php($code, settings: ['-d', 'sys_temp_dir='.$this->directory.'/tmp[1]']))
        ->toBe([sprintf('The temporary directory %s/tmp[1] holds `*`, `?`, `[`, `]`, `\` or a line break, which unzip reads as a pattern or zipinfo prints as two lines. Set TMPDIR, or sys_temp_dir in php.ini, to a path without them.', $this->directory), 0])
        ->and($this->tools->calls('curl'))->toBe([]);
});

it('takes a relative temporary directory from the working directory', function (): void {
    $code = sprintf(
        'chdir(%s); $tester = new %s(new %s(fn (): %s => new %s(%s), 1000)); $tester->execute(["version" => "155.0.8059.39", "--pin-file" => "pinned"]); echo $tester->getDisplay();',
        var_export($this->directory, true),
        CommandTester::class,
        PinCommand::class,
        CommandLine::class,
        CommandLine::class,
        var_export($this->tools->commandLine()->environment(), true),
    );

    [$output, $exitCode] = php($code, settings: ['-d', 'sys_temp_dir=tmp']);

    expect([$exitCode, $output])->toBe([0, implode('', array_map(static fn (Platform $platform): string => 'Download '.Download::url('155.0.8059.39', $platform)."\n", Platform::cases()))."Wrote 155.0.8059.39 to pinned. Review the change before you commit it.\n"])
        ->and($this->tools->calls('curl'))->each()->toMatch('~ --output '.preg_quote($this->directory, '~').'/tmp/cdp-browser-pin-[0-9a-f]{16}/archive\.zip ~')
        ->and(scandir($this->directory.'/tmp'))->toBe(['.', '..']);
});

it('resolves and checks a temporary directory in this process', function (): void {
    $before = (string) getcwd();
    chdir($this->directory);
    mkdir($this->directory.'/clean');
    mkdir($this->directory.'/x[1]');
    mkdir($this->directory.'/real[1]');
    symlink($this->directory.'/clean', $this->directory.'/a[1]');
    symlink($this->directory.'/real[1]', $this->directory.'/link');

    try {
        expect(Environment::temporaryDirectory('tmp'))->toBe($this->directory.'/tmp')
            // PHP gives an empty value for TMPDIR=/.
            ->and(Environment::temporaryDirectory(''))->toBe('/')
            ->and(Environment::temporaryDirectory('/'))->toBe('/')
            // safe-unzip checks only the real path of the archive.
            ->and(Environment::temporaryDirectory($this->directory.'/a[1]'))->toBe($this->directory.'/a[1]')
            ->and(Environment::temporaryDirectory($this->directory.'/x[1]/..'))->toBe($this->directory.'/x[1]/..')
            ->and(fn (): string => Environment::temporaryDirectory('tmp[1]'))
            ->toThrow(installerException(sprintf('The temporary directory %s/tmp[1] holds `*`', $this->directory)))
            ->and(fn (): string => Environment::temporaryDirectory($this->directory.'/link'))
            ->toThrow(installerException(sprintf('The temporary directory %s/real[1] holds `*`', realpath($this->directory))));
    } finally {
        chdir($before);
    }
});

it('puts its directory in the root for TMPDIR=/, as PHP gives an empty temporary directory there', function (): void {
    $code = sprintf(
        'try { new %s(new %s(fn (): %s => new %s(%s), 1000))->execute(["version" => "155.0.8059.39", "--pin-file" => %s]); } catch (%s $exception) { echo $exception->getMessage(); }',
        CommandTester::class,
        PinCommand::class,
        CommandLine::class,
        CommandLine::class,
        var_export($this->tools->commandLine()->environment(), true),
        var_export($this->directory.'/pinned', true),
        InstallerException::class,
    );

    [$output, $exitCode] = php($code, ['TMPDIR' => '/']);

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('~\AThe directory /cdp-browser-pin-[0-9a-f]{16} could not be created\.\z~')
        ->and($this->tools->calls('curl'))->toBe([]);
})->skip(posix_geteuid() === 0, 'Root writes into the root directory.');

it('checks the pin file before the first download', function (Closure $arrange, string $message): void {
    $file = $arrange->call($this);
    assert(is_string($file));
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    expect(fn (): int => $tester->execute(['version' => '155.0.8059.39', '--pin-file' => $file]))
        ->toThrow(installerException(sprintf($message, $file, dirname($file))))
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([
    'a directory' => [onTestCase(function (): string {
        mkdir($this->directory.'/pinned');

        return $this->directory.'/pinned';
    }), 'The pin file %s is not a file. Remove it, or name another file with --pin-file.'],
    'in a missing directory' => [onTestCase(fn (): string => $this->directory.'/missing/pinned'), 'The pin file %s cannot be written: the directory %s does not exist or is not writable.'],
    'in a read-only directory' => [onTestCase(function (): string {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('Root writes into a directory of mode 0555.');
        }

        mkdir($this->directory.'/read-only', 0o555);

        return $this->directory.'/read-only/pinned';
    }), 'The pin file %s cannot be written: the directory %s does not exist or is not writable.'],
]);

it('writes no pin for an archive that install refuses', function (?array $entries, Closure $exception): void {
    $archive = $this->directory.'/refused.zip';

    /** @var array<string, array{string, int}>|null $entries */
    if ($entries === null) {
        file_put_contents($archive, "<html>Not Found</html>\n");
    } else {
        zipArchive($archive, $entries);
    }

    $this->tools->serve('155.0.8059.39', Platform::MacX64, $archive);
    $file = $this->directory.'/pinned';
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    expect(fn (): int => $tester->execute(['version' => '155.0.8059.39', '--pin-file' => $file]))
        ->toThrow($exception)
        ->and(file_exists($file))->toBeFalse()
        // mac-x64 is the third platform: the pin stops at its archive.
        ->and($this->tools->calls('curl'))->toHaveCount(3)
        ->and(pinDirectories($this->tools))->toHaveCount(1)
        ->and(array_filter(pinDirectories($this->tools), file_exists(...)))->toBe([]);
})->with([
    'an error page with status 200' => [null, installerException('is no ZIP archive', InvalidArchiveException::class)],
    'an archive without the binary' => [['chrome-headless-shell-mac-x64/readme' => ['x', 0o100644]], installerException('does not contain chrome-headless-shell-mac-x64/chrome-headless-shell.')],
]);

it('compares the MD5 of each download with the one in x-goog-hash', function (string $headers): void {
    $this->tools->sendHeaders($headers);
    $file = $this->directory.'/pinned';
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    $tester->execute(['version' => '155.0.8059.39', '--pin-file' => $file]);

    expect(Pin::read($file)->hash(Platform::LinuxX64))->toBe(hash_file('sha256', $this->archive))
        ->and($tester->getDisplay())->not()->toContain('MD5')
        ->and($this->tools->calls('curl'))->toHaveCount(4)
        ->each()->toMatch('~ --dump-header '.preg_quote(pinDirectories($this->tools)[0] ?? 'none', '~').'/headers --write-out ~');
})->with([
    'two lines, as Google sends them' => "HTTP/2 {status}\r\nx-goog-hash: crc32c=le6WFw==\r\nx-goog-hash: md5={md5}\r\n\r\n",
    'a list in one line' => "HTTP/2 {status}\r\nx-goog-hash: crc32c=le6WFw==, md5={md5}\r\n\r\n",
    'another case' => "HTTP/2 {status}\r\nX-Goog-Hash: MD5={md5}\r\n\r\n",
    'the last answer, after one of a proxy' => "HTTP/1.1 200 Connection established\r\nx-goog-hash: md5=AAAAAAAAAAAAAAAAAAAAAA==\r\n\r\nHTTP/2 {status}\r\nx-goog-hash: md5={md5}\r\n\r\n",
    // From the first byte on: no answer to split off.
    'no status line' => "x-goog-hash: md5={md5}\r\n\r\n",
]);

it('writes no pin for a download whose MD5 differs from x-goog-hash, and keeps the old pin file', function (Platform $platform, int $downloads): void {
    $other = base64_encode(md5('other', true));
    $this->tools->sendHeaders("HTTP/2 {status}\r\nx-goog-hash: crc32c=le6WFw==\r\nx-goog-hash: md5={$other}\r\n\r\n", $platform);
    $old = file_get_contents($this->pinFile);
    $archive = $platform === Platform::LinuxX64 ? $this->archive : $this->directory.'/'.$platform->value.'.zip';
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    expect(fn (): int => $tester->execute(['version' => '155.0.8059.39', '--pin-file' => $this->pinFile]))
        ->toThrow(checksumException(sprintf('The MD5 of the download for %s is %s, the storage sent %s in x-goog-hash. The download is broken; no pin was written.', $platform->value, base64_encode((string) md5_file($archive, true)), $other)))
        ->and(file_get_contents($this->pinFile))->toBe($old)
        ->and($this->tools->calls('curl'))->toHaveCount($downloads)
        ->and(pinDirectories($this->tools))->toHaveCount(1)
        ->and(array_filter(pinDirectories($this->tools), file_exists(...)))->toBe([]);
})->with([
    'the first platform' => [Platform::LinuxX64, 1],
    'the last platform' => [Platform::MacArm64, 4],
]);

it('pins without an MD5 in x-goog-hash, and says so for each platform', function (string $headers): void {
    $this->tools->sendHeaders($headers);
    $file = $this->directory.'/pinned';
    $tester = new CommandTester(new PinCommand(fn (): CommandLine => $this->tools->commandLine(), 1000));

    $tester->execute(['version' => '155.0.8059.39', '--pin-file' => $file]);

    expect(Pin::read($file)->hash(Platform::MacArm64))->toBe(hash_file('sha256', $this->directory.'/mac-arm64.zip'))
        ->and($tester->getDisplay())->toBe(implode('', array_map(static fn (Platform $platform): string => 'Download '.Download::url('155.0.8059.39', $platform)."\n"
            .sprintf("The storage sent no MD5 for %s; pin compared none. The SHA-256 in the pin still checks install.\n", $platform->value), Platform::cases()))
            .sprintf("Wrote 155.0.8059.39 to %s. Review the change before you commit it.\n", $file));
})->with([
    'no x-goog-hash' => "HTTP/2 {status}\r\ncontent-type: application/zip\r\n\r\n",
    'only the CRC32C' => "HTTP/2 {status}\r\nx-goog-hash: crc32c=le6WFw==\r\n\r\n",
    'an MD5 that is no base64' => "HTTP/2 {status}\r\nx-goog-hash: md5=not*base64\r\n\r\n",
    'an MD5 of 15 bytes' => "HTTP/2 {status}\r\nx-goog-hash: md5=AAAAAAAAAAAAAAAAAAAA\r\n\r\n",
    'only in the answer of a proxy' => "HTTP/1.1 200 Connection established\r\nx-goog-hash: md5={md5}\r\n\r\nHTTP/2 {status}\r\n\r\n",
    'no headers' => '',
]);

it('pins through the application, and removes each of its temporary files', function (): void {
    $file = $this->directory.'/pinned';
    $tester = $this->cli();

    $tester->run(['command' => 'pin', 'version' => '155.0.8059.39', '--pin-file' => $file]);

    expect(Pin::read($file)->hash(Platform::LinuxX64))->toBe(hash_file('sha256', $this->archive))
        ->and($tester->getDisplay())->toContain(sprintf('Wrote 155.0.8059.39 to %s.', $file))
        ->and(pinDirectories($this->tools))->toHaveCount(1)
        ->and(array_filter(pinDirectories($this->tools), file_exists(...)))->toBe([]);
});

it('runs install and pin as root only with --allow-root, and names the risk and the option', function (array $input): void {
    /** @var array{command: string} $input */
    expect(fn () => $this->cli(user: 0)->run($input + ['--pin-file' => $this->pinFile]))
        ->toThrow(installerException(sprintf(
            '`%s` does not run as root: it writes files that root owns, and a later run as another user cannot replace them. Only `deps` needs root. Where no other user runs it, as in a container, add --allow-root.',
            $input['command'],
        )))
        ->and($this->tools->calls('curl'))->toBe([]);
})->with([
    'install' => [['command' => 'install']],
    'pin' => [['command' => 'pin', 'version' => '155.0.8059.39']],
]);

it('installs and pins as root with --allow-root', function (): void {
    $tester = $this->cli(user: 0);
    $pinned = $this->directory.'/pinned';

    $tester->run(['command' => 'install', '--pin-file' => $this->pinFile, '--allow-root' => true]);
    $installed = $tester->getDisplay();
    $tester->run(['command' => 'pin', 'version' => '155.0.8059.39', '--pin-file' => $pinned, '--allow-root' => true]);

    expect($installed)->toContain('chrome-headless-shell for linux64 is at')
        ->and(Pin::read($pinned)->hash(Platform::LinuxX64))->toBe(hash_file('sha256', $this->archive))
        ->and($this->tools->calls('curl'))->toHaveCount(5);
});

it('gives each tool of install and pin the timeout of CDP_BROWSER_TIMEOUT', function (array $input): void {
    $this->tools->add('curl', 'sleep(5);');
    $tester = $this->cli([Installer::TIMEOUT => '1']);
    $start = microtime(true);

    expect(fn (): int => $tester->run($input))
        ->toThrow(downloadException('exceeded the timeout of 1 seconds.'))
        ->and(microtime(true) - $start)->toBeLessThan(4.0);
})->with([
    'install' => onTestCase(fn (): array => ['command' => 'install', '--pin-file' => $this->pinFile]),
    'pin' => onTestCase(fn (): array => ['command' => 'pin', 'version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']),
]);

it('stops only the commands that read CDP_BROWSER_TIMEOUT or CDP_BROWSER_LOCK_TIMEOUT at a wrong value', function (string $variable, string $value, int $example, array $stopped): void {
    $tester = function (int $user, array $environment = []) use ($variable, $value): ApplicationTester {
        /** @var array<string, string> $environment */
        $application = Cli::application(['CDP_BROWSER_PATH' => $this->directory.'/cache', $variable => $value, ...$environment], $user, static fn (): Platform => Platform::LinuxX64, $this->tools->commandLine(), musl: false);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return new ApplicationTester($application);
    };
    $own = [Installer::OWN_BROWSER => $this->tools->browser()];
    $commands = [
        'list' => [1000, [], ['command' => 'list']],
        'deps' => [0, [], ['command' => 'deps']],
        'pin' => [1000, [], ['command' => 'pin', 'version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']],
        'install' => [1000, [], ['command' => 'install', '--pin-file' => $this->pinFile]],
        'path' => [1000, [], ['command' => 'path', '--pin-file' => $this->pinFile]],
        'install with CDP_BROWSER_BINARY' => [1000, $own, ['command' => 'install']],
        'path with CDP_BROWSER_BINARY' => [1000, $own, ['command' => 'path']],
    ];
    $message = sprintf('%s `%s` is not a whole number of seconds above 0, such as %d.', $variable, $value, $example);
    $results = [];

    foreach ($commands as $name => [$user, $environment, $input]) {
        try {
            $results[$name] = $tester($user, $environment)->run($input) === 0 ? 'runs' : 'fails';
        } catch (InstallerException $installerException) {
            $results[$name] = $installerException::class === InstallerException::class && $installerException->getMessage() === $message ? 'stops' : $installerException->getMessage();
        }
    }

    expect($results)->toBe(array_map(static fn (string $name): string => in_array($name, $stopped, true) ? 'stops' : 'runs', array_combine(array_keys($commands), array_keys($commands))));
})->with([
    // Each tool reads the timeout; deps gives its package manager none.
    'CDP_BROWSER_TIMEOUT' => [Installer::TIMEOUT, '15m', 900, ['pin', 'install', 'path', 'install with CDP_BROWSER_BINARY', 'path with CDP_BROWSER_BINARY']],
    // Only an installer with a cache takes a lock; path reads the variable with the cache.
    'CDP_BROWSER_LOCK_TIMEOUT' => [Installer::LOCK_TIMEOUT, '15m', 600, ['install', 'path']],
]);

it('stops pin at a wrong CDP_BROWSER_TIMEOUT before it downloads', function (): void {
    $application = Cli::application([Installer::TIMEOUT => '0'], 1000, static fn (): Platform => Platform::LinuxX64, $this->tools->commandLine(), musl: false);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    expect(fn (): int => new ApplicationTester($application)->run(['command' => 'pin', 'version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']))
        ->toThrow(installerException('CDP_BROWSER_TIMEOUT `0` is not a whole number of seconds above 0'))
        ->and($this->tools->calls('curl'))->toBe([]);
});

it('prints the version that Composer installed', function (): void {
    $application = Cli::application([], 1000, Platform::current(...), musl: false);
    $application->setAutoExit(false);

    $tester = new ApplicationTester($application);

    $tester->run(['--version' => true]);

    expect(InstalledVersions::getPrettyVersion(Cli::PACKAGE))->toBeString()->not()->toBe('')
        ->and(trim($tester->getDisplay()))->toBe('cdp-browser '.InstalledVersions::getPrettyVersion(Cli::PACKAGE));
});

it('names the reason when Composer did not install the package', function (): void {
    expect(Cli::version('sorge-it/elsewhere'))->toBe('of no known version: Composer does not list sorge-it/elsewhere');
});

it('names the reason without the autoloader of Composer', function (): void {
    $process = new Process([PHP_BINARY, '-r', sprintf('require %s; echo %s::version(%s);', var_export(dirname(__DIR__, 2).'/src/Console/Cli.php', true), Cli::class, var_export(Cli::PACKAGE, true))]);
    $process->mustRun();

    expect($process->getOutput())->toBe('of no known version: Composer does not list sorge-it/cdp-browser-installer');
});

it('installs the fixed package list through apt as root, and shows its output', function (): void {
    $tester = $this->cli(user: 0);

    $tester->run(['command' => 'deps']);

    expect($this->tools->calls('apt-get'))->toBe([
        'update',
        'install --yes --no-install-recommends '.implode(' ', SystemLibraries::APT),
    ])
        ->and($tester->getDisplay())->toContain("apt-get update\napt-get install\n");
});

it('needs root for the system libraries', function (): void {
    expect(fn () => $this->cli()->run(['command' => 'deps']))
        ->toThrow(installerException('`deps` installs packages through apt or apk and needs root. Run it with sudo.'))
        ->and($this->tools->calls('apt-get'))->toBe([]);
});

it('needs apk or apt-get in PATH', function (): void {
    $application = Cli::application([], 0, static fn (): Platform => Platform::LinuxX64, new CommandLine(['PATH' => $this->directory.'/empty']), musl: false);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    expect(fn (): int => new ApplicationTester($application)->run(['command' => 'deps']))
        ->toThrow(installerException('`deps` installs through apt or apk, and this system has neither.'))
        ->and($this->tools->calls('apt-get'))->toBe([]);
});

it('gives the package manager no timeout', function (): void {
    // Longer than the timeout of the command line of the application.
    $this->tools->packageManager('apt-get', 'if ($argv[1] === "update") { usleep(1_500_000); }');
    $application = Cli::application([], 0, static fn (): Platform => Platform::LinuxX64, $this->tools->commandLine(timeout: 1), musl: false);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    $tester = new ApplicationTester($application);

    $tester->run(['command' => 'deps']);

    expect($tester->getDisplay())->toContain('The packages are installed.')
        ->and($this->tools->calls('apt-get'))->toHaveCount(2);
});

it('finds the package manager in the PATH of the process, and gives it the environment of the process', function (): void {
    $code = sprintf(
        '$application = %s::application(environment: getenv(), user: 0, platform: static fn (): %s => %s::LinuxX64, musl: false); $application->setAutoExit(false); exit($application->run(new %s(["command" => "deps"])));',
        Cli::class,
        Platform::class,
        Platform::class,
        ArrayInput::class,
    );

    [$output, $exitCode] = php($code, [
        'APT_CONFIG' => $this->directory.'/apt.conf',
        // The caller's value gives way: apt-get asks no question.
        'DEBIAN_FRONTEND' => 'dialog',
        'PATH' => $this->directory.'/tools/bin',
        'http_proxy' => 'http://proxy.test:3128',
    ]);

    expect([$output, $exitCode])->toBe(["apt-get update\napt-get install\nThe packages are installed.\n", 0])
        ->and($this->tools->environment('apt-get'))->toMatchArray([
            'APT_CONFIG' => $this->directory.'/apt.conf',
            'DEBIAN_FRONTEND' => 'noninteractive',
            'LC_ALL' => 'C',
            'PATH' => $this->directory.'/tools/bin',
            'http_proxy' => 'http://proxy.test:3128',
        ]);
});

it('has nothing to install on macOS', function (Platform $platform): void {
    $tester = $this->cli(platform: $platform);

    $tester->run(['command' => 'deps']);

    expect($tester->getDisplay())->toContain('Nothing to do.')
        ->and($this->tools->calls('apt-get'))->toBe([]);
})->with([Platform::MacX64, Platform::MacArm64]);

it('starts the application from bin/cdp-browser with the environment of the process', function (): void {
    $browser = $this->tools->browser();
    $code = sprintf('$_SERVER["argv"] = ["cdp-browser", "path"]; require %s;', var_export(dirname(__DIR__, 2).'/bin/cdp-browser', true));

    expect(php($code, [Installer::OWN_BROWSER => $browser, 'CDP_BROWSER_PATH' => $this->directory.'/cache']))->toBe([$browser."\n", 0]);
});

it('ends bin/cdp-browser with the message and exit code 1 when the command stops', function (): void {
    $code = sprintf('$_SERVER["argv"] = ["cdp-browser", "path", "--pin-file", %s]; require %s;', var_export($this->directory.'/missing', true), var_export(dirname(__DIR__, 2).'/bin/cdp-browser', true));

    expect(php($code, ['CDP_BROWSER_PATH' => $this->directory.'/cache']))
        ->toBe([sprintf("There is no pin file at %s/missing. Write one with `cdp-browser pin <version>`.\n", $this->directory), 1]);
});

it('prints only the message of an InstallerException on the error output, without file, line, usage or wrap, and ends with its exit code', function (array $input, string $message, int $exitCode): void {
    $this->tools->sendHeaders("HTTP/2 {status}\r\nx-goog-hash: md5=".base64_encode(md5('other', true))."\r\n\r\n", Platform::LinuxX64);
    $application = Cli::application(['CDP_BROWSER_PATH' => $this->directory.'/cache'], 1000, static fn (): Platform => Platform::LinuxX64, $this->tools->commandLine(), musl: false);
    $application->setAutoExit(false);

    $tester = new ApplicationTester($application);

    expect($tester->run($input, ['capture_stderr_separately' => true]))->toBe($exitCode)
        ->and($tester->getDisplay())->not()->toContain($message)
        ->and($tester->getErrorOutput())->toBe($message."\n");
})->with([
    'an InstallerException' => [
        onTestCase(fn (): array => ['command' => 'path', '--pin-file' => $this->pinFile]),
        'chrome-headless-shell 155.0.8059.39 for linux64 is not installed. Run `cdp-browser install`.',
        1,
    ],
    'a ChecksumException' => [
        onTestCase(fn (): array => ['command' => 'pin', 'version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']),
        onTestCase(fn (): string => sprintf('The MD5 of the download for linux64 is %s, the storage sent %s in x-goog-hash. The download is broken; no pin was written.', base64_encode((string) md5_file($this->archive, true)), base64_encode(md5('other', true)))),
        65,
    ],
    'a DownloadException' => [
        onTestCase(function (): array {
            $this->tools->respondWith(503);

            return ['command' => 'install', '--pin-file' => $this->pinFile];
        }),
        sprintf('%s answered with status 503.', Download::url('155.0.8059.39', Platform::LinuxX64)),
        75,
    ],
    'a message with a tag of Symfony' => [
        onTestCase(fn (): array => ['command' => 'path', '--pin-file' => $this->directory.'/<info>pin</info>']),
        onTestCase(fn (): string => sprintf('There is no pin file at %s/<info>pin</info>. Write one with `cdp-browser pin <version>`.', $this->directory)),
        1,
    ],
    'with --quiet' => [
        onTestCase(fn (): array => ['command' => 'path', '--pin-file' => $this->pinFile, '--quiet' => true]),
        'chrome-headless-shell 155.0.8059.39 for linux64 is not installed. Run `cdp-browser install`.',
        1,
    ],
]);

it('shows an InstallerException as Symfony does with -v, with the trace and the usage', function (): void {
    $application = Cli::application(['CDP_BROWSER_PATH' => $this->directory.'/cache'], 1000, static fn (): Platform => Platform::LinuxX64, $this->tools->commandLine(), musl: false);
    $application->setAutoExit(false);

    $tester = new ApplicationTester($application);

    expect($tester->run(['command' => 'path', '--pin-file' => $this->pinFile, '-v' => true], ['capture_stderr_separately' => true]))->toBe(1)
        ->and($tester->getErrorOutput())->toMatch('~^In PathCommand\.php line \d+:$~m')
        ->toContain('['.InstallerException::class.' (1)]')
        ->toContain('Exception trace:')
        ->toContain(PathCommand::class.'->execute()')
        ->toContain('path [--pin-file PIN-FILE]');
});

it('shows another exception as Symfony does, and leaves an error to PHP', function (): void {
    $application = Cli::application(['CDP_BROWSER_PATH' => $this->directory.'/cache'], 1000, static fn (): Platform => throw new LogicException('A bug.'), $this->tools->commandLine(), musl: false);
    $application->setAutoExit(false);

    $tester = new ApplicationTester($application);
    $error = Cli::application(['CDP_BROWSER_PATH' => $this->directory.'/cache'], 1000, static fn (): Platform => Platform::from('sparc'), $this->tools->commandLine(), musl: false);
    $error->setAutoExit(false);

    expect($tester->run(['command' => 'path', '--pin-file' => $this->pinFile], ['capture_stderr_separately' => true]))->toBe(1)
        ->and($tester->getErrorOutput())->toMatch('~\A\nIn CliTest\.php line \d+:\n.*\n  A bug\.  \n.*\n\npath \[--pin-file PIN-FILE\]\n\n\z~')
        ->and(fn (): int => new ApplicationTester($error)->run(['command' => 'path', '--pin-file' => $this->pinFile]))->toThrow(ValueError::class);
});

it('takes the autoloader that the proxy of Composer in vendor/bin names', function (): void {
    $browser = $this->tools->browser();
    $autoload = $this->directory.'/autoload.php';
    file_put_contents($autoload, sprintf("<?php\n\necho \"autoload\\n\";\n\nreturn require %s;\n", var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true)));
    $code = sprintf(
        '$GLOBALS["_composer_autoload_path"] = %s; $_SERVER["argv"] = ["cdp-browser", "install"]; require %s;',
        var_export($autoload, true),
        var_export(dirname(__DIR__, 2).'/bin/cdp-browser', true),
    );

    [$output, $exitCode] = php($code, [Installer::OWN_BROWSER => $browser]);

    expect($exitCode)->toBe(0)
        ->and($output)->toBe(sprintf("autoload\nThe system's browser is at %s (CDP_BROWSER_BINARY).\n", $browser));
})->skip(posix_geteuid() === 0, 'install does not run as root.');

it('runs the commands that need no cache directory without HOME, and names the variables for the others', function (): void {
    $bin = var_export(dirname(__DIR__, 2).'/bin/cdp-browser', true);
    // The fakes for pin and deps.
    $unset = ['HOME' => false, 'XDG_CACHE_HOME' => false, 'CDP_BROWSER_PATH' => false, Installer::OWN_BROWSER => false, 'PATH' => $this->directory.'/tools/bin:'.$this->directory.'/tools/system'];
    $run = static fn (string ...$argv): array => php(sprintf('$_SERVER["argv"] = %s; require %s;', var_export(['cdp-browser', ...$argv], true), $bin), $unset);

    [$list, $listed] = $run('list');
    [$pin, $pinned] = $run('pin', '155.0.8059.39', '--pin-file', $this->directory.'/pinned');
    [$deps, $depsed] = $run('deps');
    [$path, $pathed] = $run('path', '--pin-file', $this->pinFile);

    expect($listed)->toBe(0)
        ->and($list)->toContain('Install the pinned chrome-headless-shell')
        ->and($pinned)->toBe(0)
        ->and($pin)->toContain('Wrote 155.0.8059.39 to')
        // deps needs root on Linux, and on macOS it has nothing to do.
        ->and($depsed)->toBe(PHP_OS_FAMILY === 'Darwin' ? 0 : 1)
        ->and($deps)->toContain(PHP_OS_FAMILY === 'Darwin' ? 'Nothing to do.' : '`deps` installs packages through apt or apk and needs root.')
        ->and($pathed)->toBe(1)
        ->and($path)->toContain('Neither CDP_BROWSER_PATH, an absolute XDG_CACHE_HOME nor HOME is set, so there is no cache directory.')
        ->not()->toContain('Fatal error');
})->skip(posix_geteuid() === 0, 'pin does not run as root.');

it("uses the system's browser without a cache directory", function (): void {
    $browser = $this->tools->browser();
    $application = Cli::application([Installer::OWN_BROWSER => $browser], 1000, static fn (): Platform => Platform::LinuxX64, $this->tools->commandLine(), musl: false);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    $tester = new ApplicationTester($application);

    $tester->run(['command' => 'install']);

    $installed = $tester->getDisplay();
    $tester->run(['command' => 'path']);

    expect($installed)->toBe(sprintf("The system's browser is at %s (CDP_BROWSER_BINARY).\n", $browser))
        ->and(trim($tester->getDisplay()))->toBe($browser);
});

it('needs a cache directory to install the pinned browser', function (): void {
    expect(fn (): string => new Installer($this->tools->commandLine(), null, musl: false)->install(Pin::read($this->pinFile), Platform::LinuxX64))
        ->toThrow(installerException("This installer has no cache directory: it was made for the system's browser."));
});

it('finds the platform only for a command that needs it', function (): void {
    $application = Cli::application(['HOME' => '/home/me'], 1000, static fn (): Platform => Platform::detect('Windows', 'AMD64'), $this->tools->commandLine(), musl: false);
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);

    $tester = new ApplicationTester($application);

    $tester->run(['command' => 'pin', 'version' => '155.0.8059.39', '--pin-file' => $this->directory.'/pinned']);

    expect($tester->getDisplay())->toContain('Wrote 155.0.8059.39 to')
        ->and(fn (): int => $tester->run(['command' => 'install', '--pin-file' => $this->pinFile]))
        ->toThrow(installerException('There is no chrome-headless-shell for Windows on AMD64.'));
});

it('lists the four commands', function (): void {
    $application = Cli::application(['HOME' => '/home/me'], 1000, Platform::current(...), musl: false);

    expect(array_keys(array_filter(
        $application->all(),
        static fn (string $name): bool => ! in_array($name, ['help', 'list', 'completion', '_complete'], true),
        ARRAY_FILTER_USE_KEY,
    )))->toEqualCanonicalizing(['pin', 'install', 'path', 'deps'])
        ->and($application)->toBeInstanceOf(Application::class);
});
