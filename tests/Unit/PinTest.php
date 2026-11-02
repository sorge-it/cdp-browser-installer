<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

/**
 * @return array<string, string>
 */
function hashes(): array
{
    return [
        'linux64' => str_repeat('a', 64),
        'linux-arm64' => str_repeat('b', 64),
        'mac-x64' => str_repeat('c', 64),
        'mac-arm64' => str_repeat('d', 64),
    ];
}

it('reads what it writes', function (): void {
    $file = temporaryDirectory().'/.chrome-version';

    new Pin('155.0.8059.39', hashes())->write($file);
    $pin = Pin::read($file);

    expect($pin->version)->toBe('155.0.8059.39')
        ->and($pin->hash(Platform::MacX64))->toBe(str_repeat('c', 64))
        ->and(json_decode((string) file_get_contents($file), true))->toBe(['version' => '155.0.8059.39', 'sha256' => hashes()]);
});

it('needs a valid SHA-256 for every platform', function (?string $hash): void {
    $hashes = $hash === null ? array_diff_key(hashes(), ['mac-arm64' => true]) : ['mac-arm64' => $hash] + hashes();

    expect(fn (): Pin => new Pin('155.0.8059.39', $hashes))
        ->toThrow(installerException('The pin of 155.0.8059.39 has no valid SHA-256 for mac-arm64.'));
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
    ['{"version": "155.0.8059.39", "sha256": {"linux64": ["a"]}}', 'is not valid JSON: Maximum stack depth exceeded.'],
    ['{"version": "155.0.8059.39"}', 'needs `version` and `sha256`'],
    ['{"sha256": {}}', 'needs `version` and `sha256`'],
    // A hash that is no text counts as missing.
    [sprintf('{"version": "155.0.8059.39", "sha256": %s}', json_encode(['linux64' => 1] + hashes())), 'The pin of 155.0.8059.39 has no valid SHA-256 for linux64.'],
]);

it('writes the pin as JSON on several lines, with a line break at the end', function (): void {
    $file = temporaryDirectory().'/.chrome-version';

    new Pin('155.0.8059.39', hashes())->write($file);

    expect(file_get_contents($file))->toBe(sprintf(
        "{\n    \"version\": \"155.0.8059.39\",\n    \"sha256\": {\n        \"linux64\": \"%s\",\n        \"linux-arm64\": \"%s\",\n        \"mac-x64\": \"%s\",\n        \"mac-arm64\": \"%s\"\n    }\n}\n",
        str_repeat('a', 64),
        str_repeat('b', 64),
        str_repeat('c', 64),
        str_repeat('d', 64),
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
        var_export(array_map(static fn (string $hash): string => strtr($hash, 'abcd', 'efef'), hashes()), true),
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
