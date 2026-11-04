<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

/**
 * @return array<string, array<string, string>>
 */
function hashes(): array
{
    return [
        'chrome-headless-shell' => [
            'linux64' => str_repeat('a', 64),
            'linux-arm64' => str_repeat('b', 64),
            'mac-x64' => str_repeat('c', 64),
            'mac-arm64' => str_repeat('d', 64),
        ],
        'chromedriver' => [
            'linux64' => str_repeat('e', 64),
            'linux-arm64' => str_repeat('f', 64),
            'mac-x64' => str_repeat('0', 64),
            'mac-arm64' => str_repeat('1', 64),
        ],
    ];
}

it('reads what it writes', function (): void {
    $file = temporaryDirectory().'/.chrome-version';

    new Pin('155.0.8059.39', hashes())->write($file);
    $pin = Pin::read($file);

    expect($pin->version)->toBe('155.0.8059.39')
        ->and($pin->hash(Binary::ChromeHeadlessShell, Platform::MacX64))->toBe(str_repeat('c', 64))
        ->and($pin->hash(Binary::Chromedriver, Platform::MacX64))->toBe(str_repeat('0', 64))
        ->and(json_decode((string) file_get_contents($file), true))->toBe(['version' => '155.0.8059.39'] + hashes());
});

it('needs a valid SHA-256 of each binary for every platform', function (?string $hash): void {
    $hashes = hashes();
    unset($hashes['chromedriver']['mac-arm64']);

    if ($hash !== null) {
        $hashes['chromedriver']['mac-arm64'] = $hash;
    }

    expect(fn (): Pin => new Pin('155.0.8059.39', $hashes))
        ->toThrow(installerException('The pin of 155.0.8059.39 has no valid SHA-256 of chromedriver for mac-arm64.'));
})->with([
    'missing' => [null],
    'too short' => ['abc'],
    'upper case' => [str_repeat('D', 64)],
    // Such a hash never matches, so each run would delete the right archive.
    'line break at the end' => [str_repeat('d', 64)."\n"],
]);

it('needs a version of four numbers', function (string $version): void {
    expect(fn (): Pin => new Pin($version, hashes()))->toThrow(installerException(sprintf('`%s` is not a Chrome for Testing version', $version)));
})->with([
    'one number' => '155',
    'a channel' => 'stable',
    'a path' => '155.0.8059.39/../x',
    'line break at the end' => "155.0.8059.39\n",
]);

it('names the pin command when the file is missing', function (): void {
    expect(fn (): Pin => Pin::read(temporaryDirectory().'/.chrome-version'))
        ->toThrow(installerException('Write one with `cdp-browser pin <version>`.'));
});

it('stops at a file that is no pin', function (string $content, string $message): void {
    $file = temporaryDirectory().'/.chrome-version';
    file_put_contents($file, $content);

    expect(fn (): Pin => Pin::read($file))->toThrow(installerException($message));
})->with([
    ['{', 'is not valid JSON'],
    // A pin nests two levels deep; the parser stops at the third.
    ['{"version": "155.0.8059.39", "chromedriver": {"linux64": ["a"]}}', 'is not valid JSON: Maximum stack depth exceeded.'],
    ['{"version": "155.0.8059.39"}', 'needs `version`, `chrome-headless-shell` and `chromedriver`'],
    ['{"version": "155.0.8059.39", "chrome-headless-shell": {}}', 'needs `version`, `chrome-headless-shell` and `chromedriver`'],
    ['{"version": "155.0.8059.39", "chrome-headless-shell": {}, "chromedriver": "a"}', 'needs `version`, `chrome-headless-shell` and `chromedriver`'],
    ['{"chrome-headless-shell": {}, "chromedriver": {}}', 'needs `version`, `chrome-headless-shell` and `chromedriver`'],
    // A hash that is no text counts as missing.
    [json_encode(['version' => '155.0.8059.39', 'chrome-headless-shell' => ['linux64' => 1] + hashes()['chrome-headless-shell'], 'chromedriver' => hashes()['chromedriver']]), 'The pin of 155.0.8059.39 has no valid SHA-256 of chrome-headless-shell for linux64.'],
]);

it('names the command that writes a pin of version 1 again', function (): void {
    $file = temporaryDirectory().'/.chrome-version';
    file_put_contents($file, json_encode(['version' => '155.0.8059.39', 'sha256' => hashes()['chrome-headless-shell']]));

    expect(fn (): Pin => Pin::read($file))->toThrow(installerException(sprintf(
        'The pin file %s pins chrome-headless-shell only, in the form of version 1. Write it again with `cdp-browser pin 155.0.8059.39`: it pins chrome-headless-shell and chromedriver.',
        $file,
    )));
});

it('checks the version of a pin of version 1 before its message names it', function (): void {
    $file = temporaryDirectory().'/.chrome-version';
    file_put_contents($file, json_encode(['version' => "155.0.8059.39\e[2J", 'sha256' => hashes()['chrome-headless-shell']]));

    expect(fn (): Pin => Pin::read($file))->toThrow(installerException('is not a Chrome for Testing version'));
});

it('writes the pin as JSON on several lines, with a line break at the end', function (): void {
    $file = temporaryDirectory().'/.chrome-version';

    new Pin('155.0.8059.39', hashes())->write($file);

    expect(file_get_contents($file))->toBe(sprintf(
        "{\n    \"version\": \"155.0.8059.39\",\n    \"chrome-headless-shell\": {\n        \"linux64\": \"%s\",\n        \"linux-arm64\": \"%s\",\n        \"mac-x64\": \"%s\",\n        \"mac-arm64\": \"%s\"\n    },\n    \"chromedriver\": {\n        \"linux64\": \"%s\",\n        \"linux-arm64\": \"%s\",\n        \"mac-x64\": \"%s\",\n        \"mac-arm64\": \"%s\"\n    }\n}\n",
        ...array_merge(...array_values(array_map(array_values(...), hashes()))),
    ));
});

it('keeps the whole old pin file when the write of the new one fails', function (): void {
    $file = temporaryDirectory().'/.chrome-version';
    new Pin('155.0.8059.39', hashes())->write($file);
    $old = file_get_contents($file);
    // With a file size limit of 0, each write fails, as on a full disk. SIGXFSZ is ignored, else
    // it ends the process at the write. The limit goes before the coverage of pcov is written.
    $code = sprintf(
        'pcntl_signal(SIGXFSZ, SIG_IGN); posix_setrlimit(POSIX_RLIMIT_FSIZE, 0, POSIX_RLIMIT_INFINITY); try { @new %s("156.0.0.1", %s)->write(%s); } catch (%s $exception) { echo $exception->getMessage(); } posix_setrlimit(POSIX_RLIMIT_FSIZE, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);',
        Pin::class,
        var_export(array_map(static fn (array $hashes): array => array_map(static fn (string $hash): string => strtr($hash, 'abcd', 'efef'), $hashes), hashes()), true),
        var_export($file, true),
        InstallerException::class,
    );

    expect(php($code))->toBe([sprintf('The pin file %s could not be written.', $file), 0])
        ->and(file_get_contents($file))->toBe($old)
        ->and(glob($file.'*'))->toBe([$file]);
})->skip(! function_exists('pcntl_signal'), 'The test needs pcntl to ignore SIGXFSZ.');

it('keeps a pin file that is a link, and writes the file it points to', function (): void {
    $directory = temporaryDirectory();
    mkdir($directory.'/shared');
    file_put_contents($directory.'/shared/pin.json', 'old');
    symlink('shared/pin.json', $directory.'/.chrome-version');

    new Pin('155.0.8059.39', hashes())->write($directory.'/.chrome-version');

    expect(is_link($directory.'/.chrome-version'))->toBeTrue()
        ->and(Pin::read($directory.'/shared/pin.json')->version)->toBe('155.0.8059.39')
        ->and(scandir($directory))->toBe(['.', '..', '.chrome-version', 'shared'])
        ->and(scandir($directory.'/shared'))->toBe(['.', '..', 'pin.json']);
});

it('names a pin file that it cannot read as a cause of the file system', function (): void {
    $file = temporaryDirectory().'/.chrome-version';
    new Pin('155.0.8059.39', hashes())->write($file);
    chmod($file, 0o000);

    expect(fn (): mixed => underErrorExceptions(static fn (): Pin => Pin::read($file)))
        ->toThrow(installerException(sprintf('The pin file %s could not be read.', $file)));
})->skip(posix_geteuid() === 0, 'Root reads a file of mode 0000.');

it('names a pin file that it cannot write over a directory', function (): void {
    $file = temporaryDirectory().'/.chrome-version';
    mkdir($file);

    expect(fn (): mixed => underErrorExceptions(static fn () => new Pin('155.0.8059.39', hashes())->write($file)))
        ->toThrow(installerException(sprintf('The pin file %s could not be written.', $file)));
});

it('names a pin file that it cannot write in a read-only directory', function (): void {
    $directory = temporaryDirectory();
    chmod($directory, 0o555);

    expect(fn (): mixed => underErrorExceptions(static fn () => new Pin('155.0.8059.39', hashes())->write($directory.'/.chrome-version')))
        ->toThrow(installerException(sprintf('The pin file %s/.chrome-version could not be written.', $directory)))
        ->and(scandir($directory))->toBe(['.', '..']);
})->skip(posix_geteuid() === 0, 'Root writes into a directory of mode 0555.');
