<?php

declare(strict_types=1);

// The preflight of the GIF: runs each command that demo.tape types, in the same cache, and compares
// its whole output, stdout and stderr, with what the GIF must show. Run through
// `make -C demo preflight`, in demo/app, in the container of the recording.

use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\SystemLibraries;

require __DIR__.'/vendor/autoload.php';

const VERSION = '156.0.8078.80';
const HASH = '([0-9a-f]{64})';

$tree = preg_quote(getenv('HOME').'/.cache/cdp-browser/'.VERSION.'/linux-arm64/chrome-headless-shell-linux-arm64', '/');
$version = preg_quote(VERSION, '/');
$failures = [];

// The image holds each package that `vendor/bin/cdp-browser deps` installs.
foreach (SystemLibraries::APT as $package) {
    exec('dpkg-query -W '.escapeshellarg($package).' 2>&1', $output, $code);

    if ($code !== 0) {
        $failures[] = sprintf('The image lacks the package %s.', $package);
    }
}

// The whole output of each command, as a regular expression. {pin} is the hash that the first
// command shows.
$expected = [
    'grep -e version -e linux-arm64 .chrome-version' => "    \"version\": \"{$version}\",\n        \"linux-arm64\": \"".HASH.'",',
    'vendor/bin/cdp-browser install' => 'Download '.preg_quote(Download::url(VERSION, Platform::LinuxArm64), '/')."\nchrome-headless-shell for linux-arm64 is at {$tree}\\/chrome-headless-shell\\.",
    'vendor/bin/cdp-browser path' => "{$tree}\\/chrome-headless-shell",
    '"$(vendor/bin/cdp-browser path)" --version' => "Google Chrome for Testing {$version}",
    '# The archive as a CI cache restores it, with one byte added' => '',
    'dir=~/.cache/cdp-browser/156.0.8078.80/linux-arm64' => '',
    'rm -r $dir/chrome-headless-shell-linux-arm64 $dir/.sha256' => '',
    'printf x >> $dir/chrome-headless-shell-linux-arm64.zip' => '',
    // ls prints nothing: the archive is gone.
    'vendor/bin/cdp-browser install; ls $dir' => "The SHA-256 of {$tree}\\.zip is [0-9a-f]{64}, the pin expects {pin}\\. The archive is deleted and nothing was unpacked\\.",
];

// The commands the GIF shows: each `Type` between `Show` and `Hide`, without `clear`.
$commands = [];
$shown = false;

foreach (file(__DIR__.'/../demo.tape', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if ($line === 'Show' || $line === 'Hide') {
        $shown = $line === 'Show';
    } elseif (str_starts_with($line, 'Type') && preg_match('/\AType (["\'])(.*)\1\z/', $line, $type) !== 1) {
        // Another form, such as Type@500ms or a string in backticks: this preflight cannot read it.
        $failures[] = sprintf('The line `%s` of demo.tape is not a form this preflight reads.', $line);
    } elseif ($shown && isset($type[2]) && $type[2] !== 'clear') {
        $commands[] = $type[2];
    }

    unset($type);
}

if (array_diff(array_keys($expected), $commands) !== []) {
    $failures[] = sprintf('demo.tape no longer types: %s', implode(' · ', array_diff(array_keys($expected), $commands)));
}

exec('rm -rf ~/.cache/cdp-browser');
// An assignment of the tape stays set for the next commands, as in the shell of the recording.
$assignments = '';
$pin = 'none';

foreach ($commands as $command) {
    if (! array_key_exists($command, $expected)) {
        $failures[] = sprintf('The command `%s` of demo.tape has no expected output here.', $command);

        continue;
    }

    $output = [];
    exec('bash -c '.escapeshellarg($assignments.$command).' 2>&1', $output, $code);
    $output = implode("\n", $output);

    if ($code !== 0 || preg_match('/\A'.str_replace('{pin}', $pin, $expected[$command]).'\z/', $output, $match) !== 1) {
        $failures[] = sprintf("The command `%s` ended with exit code %d and printed:\n%s", $command, $code, $output);
    } elseif (isset($match[1])) {
        $pin = $match[1];
    }

    if (preg_match('/\A\w+=\S+\z/', $command) === 1) {
        $assignments .= $command."\n";
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures)."\n");

    exit(1);
}
