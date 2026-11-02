<?php

declare(strict_types=1);

// The demo of the README: pin, install, path, a page through the browser, and a changed archive
// of a cache that install refuses. Run through `make -C demo record`, in demo/app, with a network.

use SorgeIt\CdpBrowserInstaller\Platform;

require __DIR__.'/vendor/autoload.php';

const VERSION = '156.0.8078.80';
const CACHE = '.cache/cdp-browser';

// The cache stays inside the demo app; ~/.cache is left alone.
putenv('CDP_BROWSER_PATH='.CACHE);

$platform = Platform::current();
$archive = sprintf('%s/%s/%s/%s.zip', CACHE, VERSION, $platform->value, $platform->directory());
$directory = (string) getcwd();

printf("# %s, %s %s, PHP %s. The output shows the directory of the demo app as `.`.\n", gmdate('Y-m-d\TH:i\Z'), PHP_OS_FAMILY, php_uname('m'), PHP_VERSION);

// Each step with the exit code it must end with: only the install of the changed archive fails.
$steps = [
    ['vendor/bin/cdp-browser pin '.VERSION, 0],
    ['cat .chrome-version', 0],
    ['vendor/bin/cdp-browser install', 0],
    ['vendor/bin/cdp-browser path', 0],
    ['vendor/bin/cdp-browser install', 0],
    ['"$(vendor/bin/cdp-browser path)" --version', 0],
    ['"$(vendor/bin/cdp-browser path)" --dump-dom \'data:text/html,<p>ok</p>\'', 0],
    // As on a new CI runner: a cache restored the archive, and the browser is missing.
    [sprintf('rm -rf %1$s/%2$s %1$s/.sha256', dirname($archive), $platform->directory()), 0],
    ['printf x >> '.$archive, 0],
    // The exit code of a ChecksumException.
    ['vendor/bin/cdp-browser install', 65],
    ['ls '.dirname($archive), 0],
];

// Each step is a line of the shell, printed first, then its exit code and its seconds.
foreach ($steps as [$step, $expected]) {
    printf("\n$ %s\n", $step);
    $start = microtime(true);
    $output = [];
    exec($step.' 2>&1', $output, $code);

    foreach ($output as $line) {
        echo str_replace($directory.'/', './', $line), "\n";
    }

    printf("[exit %d, %.1f s]\n", $code, microtime(true) - $start);

    if ($code !== $expected) {
        fwrite(STDERR, sprintf("The step `%s` ended with exit code %d, the demo expects %d.\n", $step, $code, $expected));

        exit(1);
    }
}
