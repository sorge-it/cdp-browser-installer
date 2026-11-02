<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\CommandLine;
use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->tools = new FakeTools($this->directory.'/tools');
});

it('downloads an archive from the source of Chrome for Testing', function (): void {
    $archive = browserArchive($this->directory.'/source.zip', Platform::MacX64);
    $this->tools->serve('155.0.8059.39', Platform::MacX64, $archive);

    new Download($this->tools->commandLine())->to('155.0.8059.39', Platform::MacX64, $this->directory.'/out.zip');

    expect(file_get_contents($this->directory.'/out.zip'))->toBe(file_get_contents($archive))
        ->and(Download::url('155.0.8059.39', Platform::MacX64))
        ->toBe('https://storage.googleapis.com/chrome-for-testing-public/155.0.8059.39/mac-x64/chrome-headless-shell-mac-x64.zip');
});

it('reports the command, its exit code and its error output', function (): void {
    expect(fn (): string => (new CommandLine)->run(['ls', $this->directory.'/missing']))
        ->toThrow(installerException('`ls` stopped with exit code'));
});

it('ends its message with the error output, without the last line break', function (): void {
    $this->tools->add('fails', 'fwrite(STDERR, "first line\nsecond line\n"); exit(3);');

    expect(fn (): string => $this->tools->commandLine()->run(['fails']))
        ->toThrow(static function (InstallerException $exception): void {
            expect($exception->getMessage())->toBe("`fails` stopped with exit code 3: first line\nsecond line");
        });
});

it('finds only a file that may run, by its path and in PATH', function (): void {
    mkdir($this->directory.'/plain');
    file_put_contents($this->directory.'/plain/tool', "#!/bin/sh\n");
    chmod($this->directory.'/plain/tool', 0o644);
    $commandLine = new CommandLine(['PATH' => $this->directory.'/plain']);

    expect($commandLine->has('tool'))->toBeFalse()
        ->and($commandLine->has($this->directory.'/plain/tool'))->toBeFalse()
        ->and($commandLine->has($this->directory.'/plain'))->toBeFalse();
});

it('removes with rm -rf --, so that no path reads as an option', function (): void {
    $this->tools->around('rm', '');

    $this->tools->commandLine()->remove([$this->directory.'/a', $this->directory.'/b'], null);

    expect($this->tools->calls('rm'))->toBe([sprintf('-rf -- %1$s/a %1$s/b', $this->directory)]);
});

it('leaves no file after an answer other than 200, or after curl stopped with part of it', function (string $curl, Closure $exception): void {
    $this->tools->add('curl', '$output = $argv[array_search("--output", $argv, true) + 1]; file_put_contents($output, "part"); '.$curl);

    expect(fn () => new Download($this->tools->commandLine())->to('155.0.8059.39', Platform::LinuxX64, $this->directory.'/out.zip'))
        ->toThrow($exception)
        ->and(file_exists($this->directory.'/out.zip'))->toBeFalse();
})->with([
    'status 503' => ['echo "503";', downloadException('answered with status 503.')],
    'status 404' => ['echo "404";', installerException('answered with status 404: version 155.0.8059.39 does not exist for linux64.')],
    'curl fails' => ['fwrite(STDERR, "curl: (18) transfer closed\n"); exit(18);', downloadException('`curl` stopped with exit code 18: curl: (18) transfer closed')],
]);

it('finds a command in the PATH the process gets, and names a missing one', function (): void {
    $commandLine = $this->tools->commandLine(system: false);

    expect($commandLine->run(['curl', 'https://storage.googleapis.com/chrome-for-testing-public/x', '--output', $this->directory.'/out']))->toBe('404')
        ->and(fn (): string => $commandLine->run(['unzip']))
        ->toThrow(installerException('`unzip` is not installed. On Debian, Ubuntu and Alpine, `cdp-browser deps` installs it.'));
});

it('finds a tool in a relative or an empty entry of PATH, as a shell does', function (string $path): void {
    $working = $this->directory.'/work';
    mkdir($working.'/bin', 0o755, true);
    copy($this->tools->add('curl', 'echo "found";'), $working.'/curl');
    copy($working.'/curl', $working.'/bin/curl');
    chmod($working.'/curl', 0o755);
    chmod($working.'/bin/curl', 0o755);
    $commandLine = new CommandLine(['PATH' => $path]);
    $directory = (string) getcwd();
    chdir($working);

    try {
        expect($commandLine->has('curl'))->toBeTrue()
            ->and($commandLine->run(['curl', '--version']))->toBe('found')
            ->and($this->tools->calls('curl'))->toBe(['--version']);
    } finally {
        chdir($directory);
    }
})->with([
    'dot' => '.',
    'relative' => 'bin',
    'empty' => '/nowhere::/elsewhere',
]);

it('looks in /usr/bin and /bin when PHP runs without PATH, not in the working directory', function (): void {
    // `sh` in the working directory of the process, as a checked-out repository can bring it.
    $working = $this->directory.'/work';
    mkdir($working);
    copy($this->tools->add('sh', ''), $working.'/sh');
    chmod($working.'/sh', 0o755);
    $code = sprintf('chdir(%s); $commandLine = new %s(); echo $commandLine->find("sh"), " ", var_export($commandLine->has("curl-of-nobody"), true);', var_export($working, true), CommandLine::class);

    [$output, $exitCode] = php($code, ['PATH' => false]);

    expect($exitCode)->toBe(0)
        ->and($output)->toBeIn(['/usr/bin/sh false', '/bin/sh false']);
});

it('gives each process the environment of PHP, its own variables, and its timeout', function (): void {
    $commandLine = new CommandLine(['ONLY' => 'this'], timeout: 7);

    expect($commandLine->run(['sh', '-c', 'echo "$ONLY $HOME"']))->toBe(sprintf("this %s\n", getenv('HOME')))
        ->and($commandLine->environment())->toBe(['ONLY' => 'this'])
        ->and($commandLine->timeout())->toBe(7);
});

it('reads no option of unzip or zipinfo from the environment', function (string $variable, string $value): void {
    $archive = browserArchive($this->directory.'/source.zip', Platform::LinuxX64);
    $this->tools->serve('155.0.8059.39', Platform::LinuxX64, $archive);
    $commandLine = $this->tools->commandLine(environment: [$variable => $value]);

    $binary = new Installer($commandLine, $this->directory.'/cache', musl: false)->install(pinFor('155.0.8059.39', $archive), Platform::LinuxX64);

    expect(is_file($binary))->toBeTrue();
})->with([
    // `-l` makes unzip list instead of unpack, so the browser would be missing.
    ['UNZIP', '-l'],
    ['UNZIPOPT', '-l'],
    // `-h-` drops the compressed size from `zipinfo -l`, a row the grammar does not know.
    ['ZIPINFO', '-h-'],
    ['ZIPINFOOPT', '-h-'],
]);

it('reports a timeout of curl as an error of the download, and leaves no file', function (): void {
    $this->tools->add('curl', 'sleep(5);');

    expect(fn () => new Download($this->tools->commandLine(timeout: 1))->to('155.0.8059.39', Platform::LinuxX64, $this->directory.'/out.zip'))
        ->toThrow(downloadException('`curl` stopped: The process', ProcessTimedOutException::class))
        ->and(file_exists($this->directory.'/out.zip'))->toBeFalse();
});

it('streams the output as it arrives, and gives the same locale to every tool', function (): void {
    $chunks = [];

    $output = new CommandLine(['LC_ALL' => 'de_DE.UTF-8'])->run(['sh', '-c', 'echo "$LC_ALL"'], function (string $chunk) use (&$chunks): void {
        $chunks[] = $chunk;
    });

    expect($output)->toBe("C\n")
        ->and($chunks)->toBe(["C\n"]);
});
