<?php

declare(strict_types=1);

// The preflight of the GIF: runs each command that demo.tape types, in the same cache, and compares
// its whole output, stdout and stderr, with what the GIF must show. Run through
// `make -C demo preflight`, in demo/app, in the container of the recording.

use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\SystemLibraries;

require __DIR__.'/vendor/autoload.php';

const VERSION = '158.0.8127.36';
const CACHE = '/ci/cdp-browser';
// The columns of the terminal that the width of demo.tape gives, and the width of its prompt `app ❯ `.
const COLUMNS = 90;
const PROMPT = 6;

$pair = CACHE.'/'.VERSION.'/linux-arm64';
$tree = preg_quote($pair.'/chrome-headless-shell-linux-arm64', '/');
$driver = preg_quote($pair.'/chromedriver-linux-arm64', '/');
$version = preg_quote(VERSION, '/');
$download = static fn (Binary $binary): string => 'Download '.preg_quote(Download::url(VERSION, $binary, Platform::LinuxArm64), '/');
$failures = [];

// The image holds each package that `vendor/bin/cdp-browser deps` installs.
foreach (SystemLibraries::APT as $package) {
    exec('dpkg-query -W '.escapeshellarg($package).' 2>&1', $output, $code);

    if ($code !== 0) {
        $failures[] = sprintf('The image lacks the package %s.', $package);
    }
}

$installed = "chrome-headless-shell for linux-arm64 is at {$tree}\\/chrome-headless-shell\\.\nchromedriver for linux-arm64 is at {$driver}\\/chromedriver\\.";
$pin = preg_quote(Pin::read(Pin::FILE)->hash(Binary::ChromeHeadlessShell, Platform::LinuxArm64), '/');

// Each command of demo.tape in its order, with its exit code and its whole output as a regular
// expression. The exit code 65 is that of a ChecksumException.
$expected = [
    ['export CDP_BROWSER_PATH='.CACHE, 0, ''],
    ['vendor/bin/cdp-browser install', 0, $download(Binary::ChromeHeadlessShell)."\n".$download(Binary::Chromedriver)."\n".$installed],
    ['"$(vendor/bin/cdp-browser path chrome-headless-shell)" --version', 0, "Google Chrome for Testing {$version}"],
    ['"$(vendor/bin/cdp-browser path chromedriver)" --version', 0, "ChromeDriver {$version} \\(.+\\)"],
    ['dir='.CACHE.'/'.VERSION.'/linux-arm64', 0, ''],
    ['rm -r $dir/*/ $dir/.sha256-pair', 0, ''],
    ['printf x >> $dir/chrome-headless-shell-linux-arm64.zip', 0, ''],
    ['vendor/bin/cdp-browser install', 65, "The SHA-256 of {$tree}\\.zip is [0-9a-f]{64}, but the pin file expects {$pin}\\. The archive is deleted and was not unpacked\\. It came from the cache: run `vendor\\/bin\\/cdp-browser install` again to download it\\."],
    // Only the deleted archive is downloaded again; the one of chromedriver comes from the cache.
    ['vendor/bin/cdp-browser install', 0, $download(Binary::ChromeHeadlessShell)."\n".$installed],
];

// The commands whose output the GIF shows: each `Type`, without the prompt of the shell and the
// captions, which start with `clear`.
$commands = [];

foreach (file(__DIR__.'/../demo.tape', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (str_starts_with($line, 'Type') && preg_match('/\AType (["\'])(.*)\1\z/', $line, $type) !== 1) {
        // Another form, such as Type@500ms or a string in backticks: this preflight cannot read it.
        $failures[] = sprintf('The line `%s` of demo.tape is not a form this preflight reads.', $line);
    } elseif (isset($type[2]) && ! str_starts_with($type[2], 'clear') && ! str_starts_with($type[2], 'export PS1=')) {
        $commands[] = $type[2];
    }

    unset($type);
}

if ($commands !== array_column($expected, 0)) {
    $failures[] = sprintf("demo.tape types:\n%s\nThe preflight expects:\n%s", implode("\n", $commands), implode("\n", array_column($expected, 0)));
}

exec('rm -rf '.CACHE);
// An assignment of the tape stays set for the next commands, as in the shell of the recording.
$assignments = '';

foreach ($expected as [$command, $exit, $pattern]) {
    $output = [];
    exec('bash -c '.escapeshellarg($assignments.$command).' 2>&1', $output, $code);
    $output = implode("\n", $output);

    if ($code !== $exit || preg_match('/\A'.$pattern.'\z/', $output) !== 1) {
        $failures[] = sprintf("The command `%s` ended with exit code %d, not %d, or printed other text:\n%s", $command, $code, $exit, $output);
    }

    if (PROMPT + strlen($command) > COLUMNS) {
        $failures[] = sprintf('The command `%s` does not fit in one row of %d columns after the prompt.', $command, COLUMNS);
    }

    // The refusal is the peak of the GIF: a row of the terminal ends inside the archive path or at a
    // space, never in a hash or in the command in backticks.
    $path = [strpos($output, ' of ') + 4, strpos($output, ' is ')];
    $quoted = [strpos($output, '`'), strrpos($output, '`') + 1];

    for ($column = COLUMNS; $exit !== 0 && $column < strlen($output); $column += COLUMNS) {
        $inPath = $column > $path[0] && $column < $path[1];
        $atSpace = $output[$column - 1] === ' ' || $output[$column] === ' ';
        $inQuote = $column > $quoted[0] && $column < $quoted[1];

        if (! $inPath && (! $atSpace || $inQuote)) {
            $failures[] = sprintf('The output of `%s` wraps at column %d in a word, a hash or a command: change the width of demo.tape and COLUMNS.', $command, $column);
        }
    }

    if (preg_match('/\A(export )?\w+=\S+\z/', $command) === 1) {
        $assignments .= $command."\n";
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");

    exit(1);
}
