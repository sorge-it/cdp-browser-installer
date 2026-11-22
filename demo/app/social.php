<?php

declare(strict_types=1);

// The screen of the social preview: the name, both binaries of the pair in one version, and three
// benefits. It installs into a cache of its own, and runs each binary from there.

const CACHE = __DIR__.'/out-social';
const VERSION = '158.0.8127.36';
const PROMPT = "\033[1;32m❯\033[0m ";

putenv('CDP_BROWSER_PATH='.CACHE);

// The output of a command; any other exit code than 0 ends the script without a last line, and
// social.tape stops with an error.
function run(string $command): string
{
    exec($command.' 2>&1', $output, $code);

    if ($code !== 0) {
        echo implode("\n", $output), "\n";
        exec('rm -rf '.CACHE);

        exit(1);
    }

    return implode("\n", $output);
}

// The text in bold, each character in a color between the stops, as #rrggbb.
function gradient(string $text, string ...$stops): string
{
    $rgb = array_map(static fn (string $stop): array => array_map(hexdec(...), str_split(ltrim($stop, '#'), 2)), $stops);
    $characters = mb_str_split($text);
    $out = '';

    foreach ($characters as $index => $character) {
        $position = $index / (count($characters) - 1) * (count($rgb) - 1);
        $stop = min((int) $position, count($rgb) - 2);
        $mix = static fn (int $channel): int => (int) round($rgb[$stop][$channel] + ($rgb[$stop + 1][$channel] - $rgb[$stop][$channel]) * ($position - $stop));
        $out .= sprintf("\033[1;38;2;%d;%d;%dm%s", $mix(0), $mix(1), $mix(2), $character);
    }

    return $out."\033[0m";
}

run('vendor/bin/cdp-browser install');
$browser = run('"$(vendor/bin/cdp-browser path chrome-headless-shell)" --version');
$driver = run('"$(vendor/bin/cdp-browser path chromedriver)" --version');
exec('rm -rf '.CACHE);

// The pinned version in green: the version carries the message. The build of ChromeDriver becomes
// a dimmed `(…)`, as in the README, so that each line of a version fits in one row.
$highlight = static fn (string $line): string => preg_replace(
    ['/'.preg_quote(VERSION, '/').'/', '/ \(.+\)\z/'],
    ["\033[1;32m".VERSION."\033[0m", " \033[2m(…)\033[0m"],
    $line,
);

// No cursor and no last newline: the screenshot shows the output only, and no line scrolls away.
echo "\033[?25l\033[H\033[2J";
echo gradient('sorge-it/cdp-browser-installer', '#f5c2e7', '#cba6f7', '#89b4fa', '#94e2d5'), "\n";
echo "\033[1mPinned chrome-headless-shell and ChromeDriver for PHP.\033[0m \033[1;33mNo Node.\033[0m\n\n";
echo PROMPT, "\"$(vendor/bin/cdp-browser path chrome-headless-shell)\" --version\n";
echo $highlight($browser), "\n";
echo PROMPT, "\"$(vendor/bin/cdp-browser path chromedriver)\" --version\n";
echo $highlight($driver), "\n\n";
echo "\033[1;35m✓\033[0m One version for both, pinned in your repository\n";
echo "\033[1;34m✓\033[0m A SHA-256 for each archive, checked before unpacking\n";
echo "\033[1;36m✓\033[0m Works with Dusk, Panther, php-webdriver, chrome-php and Mink";
