<?php

declare(strict_types=1);

// The screen of the social preview: the name, then an archive with one byte added, which
// `install` refuses. It installs into a cache of its own, and shows the archive without its directory.

// Columns of the terminal in social.tape, less the indent: the wrapped lines keep it.
const WIDTH = 76;
const CACHE = __DIR__.'/out-social';
const DIRECTORY = CACHE.'/157.0.8103.38/linux-arm64';

putenv('CDP_BROWSER_PATH='.CACHE);

exec('vendor/bin/cdp-browser install 2>&1', $installed, $code);

if ($code !== 0) {
    // No last line: social.tape waits for it and stops with an error.
    echo implode("\n", $installed), "\n";

    exit(1);
}

exec(sprintf('rm -r %1$s/*/ %1$s/.sha256-pair && printf x >> %1$s/chrome-headless-shell-linux-arm64.zip', DIRECTORY));
exec('vendor/bin/cdp-browser install 2>&1', $refused, $code);
exec('rm -rf '.CACHE);

// The exit code of a ChecksumException.
if ($code !== 65) {
    echo implode("\n", $refused), "\n";

    exit(1);
}

$refused = explode("\n", wordwrap(str_replace(DIRECTORY.'/', '', implode(' ', $refused)), WIDTH, "\n", true));

// No cursor: the screenshot shows the output only.
echo "\033[?25l\033[H\033[2J\n";
echo "  \033[1;35msorge-it/cdp-browser-installer\033[0m   Pinned Chrome and ChromeDriver. No Node.\n\n";
echo "  \033[2m# The archive as a CI cache restores it, with one byte added\033[0m\n";
echo "  $ printf x >> chrome-headless-shell-linux-arm64.zip\n";
echo "  $ vendor/bin/cdp-browser install\n";
echo "  \033[31m".implode("\n  ", $refused)."\033[0m\n\n";
echo "  \033[2mA SHA-256 for each platform in your repository, checked before unpacking.\n";
echo "  Built for browser tests that coding agents write and CI runs.\n";
echo "  CI runs Dusk, Panther, php-webdriver, chrome-php and chrome-mink-driver.\033[0m\n";
