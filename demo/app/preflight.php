<?php

declare(strict_types=1);

// The preflight of the GIF: runs each command that demo.tape types, in the same cache, and compares
// its whole output, stdout and stderr, with what the GIF must show. Run through
// `make -C demo preflight`, in demo/app, in the container of the recording.

use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\SystemLibraries;

require __DIR__.'/vendor/autoload.php';

const VERSION = '157.0.8103.38';
const HASH = '([0-9a-f]{64})';

$pair = getenv('HOME').'/.cache/cdp-browser/'.VERSION.'/linux-arm64';
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

// The whole output of each command, as a regular expression. {pin} is the hash that the first
// command shows.
$expected = [
    'grep -e version -e chrome -e linux-arm64 .chrome-version' => "    \"version\": \"{$version}\",\n    \"chrome-headless-shell\": {\n        \"linux-arm64\": \"".HASH."\",\n    \"chromedriver\": {\n        \"linux-arm64\": \"[0-9a-f]{64}\",",
    'vendor/bin/cdp-browser install' => $download(Binary::ChromeHeadlessShell)."\n".$download(Binary::Chromedriver)
        ."\nchrome-headless-shell for linux-arm64 is at {$tree}\\/chrome-headless-shell\\.\nchromedriver for linux-arm64 is at {$driver}\\/chromedriver\\.",
    '"$(vendor/bin/cdp-browser path chrome-headless-shell)" --version' => "Google Chrome for Testing {$version}",
    '"$(vendor/bin/cdp-browser path chromedriver)" --version' => "ChromeDriver {$version} \\(.+\\)",
    '# The archive as a CI cache restores it, with one byte added' => '',
    'dir=~/.cache/cdp-browser/157.0.8103.38/linux-arm64' => '',
    'rm -r $dir/*/ $dir/.sha256-pair' => '',
    'printf x >> $dir/chrome-headless-shell-linux-arm64.zip' => '',
    // ls shows the archive of chromedriver only: the changed archive is gone, and nothing is unpacked.
    'vendor/bin/cdp-browser install; ls $dir' => "The SHA-256 of {$tree}\\.zip is [0-9a-f]{64}, the pin expects {pin}\\. The archive is deleted and was not unpacked\\.\nchromedriver-linux-arm64\\.zip",
];

// The commands whose output the GIF shows: each `Type`, without the setup of the shell and `clear`.
// The pin file is typed hidden, so that the first frame shows its output.
$commands = [];

foreach (file(__DIR__.'/../demo.tape', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (str_starts_with($line, 'Type') && preg_match('/\AType (["\'])(.*)\1\z/', $line, $type) !== 1) {
        // Another form, such as Type@500ms or a string in backticks: this preflight cannot read it.
        $failures[] = sprintf('The line `%s` of demo.tape is not a form this preflight reads.', $line);
    } elseif (isset($type[2]) && $type[2] !== 'clear' && ! str_starts_with($type[2], 'export PS1=')) {
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
