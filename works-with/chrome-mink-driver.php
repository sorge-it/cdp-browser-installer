<?php

declare(strict_types=1);

// dmore/chrome-mink-driver reads a page through the installed browser on its debugging port. The
// CI job `works-with` runs this script here, after `vendor/bin/cdp-browser install`. A test
// compares the lines between the README markers, and each flag of the browser, with the README.

use Behat\Mink\Session;
use DMore\ChromeDriver\ChromeDriver;

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/check.php';

// As the command line of the README: the path from `cdp-browser path chrome-headless-shell`.
exec('vendor/bin/cdp-browser path chrome-headless-shell', $output, $code);
$binary = $output[0] ?? '';

if ($code !== 0 || $binary === '') {
    fwrite(STDERR, "`vendor/bin/cdp-browser path chrome-headless-shell` named no browser.\n");

    exit(1);
}

$profile = sys_get_temp_dir().'/works-with-'.getmypid();
$log = $profile.'.log';
$arguments = [$binary, '--headless', '--remote-debugging-port=9222', '--user-data-dir='.$profile];

// Ubuntu 24.04 blocks the user namespaces of the Chrome sandbox through AppArmor.
if (PHP_OS_FAMILY === 'Linux') {
    $arguments[] = '--no-sandbox';
}

$process = proc_open($arguments, [1 => ['file', '/dev/null', 'w'], 2 => ['file', $log, 'w']], $pipes);

try {
    $deadline = microtime(true) + 20;

    // Alpine's Chromium writes no `DevToolsActivePort`. Each browser names its WebSocket in its
    // error output once it listens.
    while (preg_match('~^DevTools listening on (ws://\S+)\n~m', (string) file_get_contents($log), $listening) !== 1) {
        if (! proc_get_status($process)['running'] || microtime(true) >= $deadline) {
            throw new RuntimeException('The browser did not listen on a debugging port within 20 seconds.');
        }

        usleep(100_000);
    }

    // Where another process listens on 127.0.0.1:9222, the browser listens on [::1]:9222, and the
    // driver would talk to the other process.
    $curl = curl_init('http://127.0.0.1:9222/json/version');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    $version = json_decode((string) curl_exec($curl), true);

    if (! is_array($version) || ($version['webSocketDebuggerUrl'] ?? null) !== $listening[1] || ! is_string($version['Browser'] ?? null)) {
        throw new RuntimeException(sprintf('Another process answers on 127.0.0.1:9222; the browser of this script listens at %s.', $listening[1]));
    }

    // README: begin
    $session = new Session(new ChromeDriver('http://127.0.0.1:9222', null, 'http://localhost'));
    $session->start();
    // README: end

    try {
        $session->visit('data:text/html,<p id="ok">chrome-mink-driver</p>');
        $text = $session->getPage()->find('css', '#ok')?->getText();
    } finally {
        $session->stop();
    }
} finally {
    proc_terminate($process);
    proc_close($process);
    // The error output of the browser stays in the log of the job.
    fwrite(STDERR, (string) file_get_contents($log));
    exec('rm -rf '.escapeshellarg($profile).' '.escapeshellarg($log));
}

// Such as "HeadlessChrome/157.0.8103.38" or "Chrome/156.0.8078.79".
$product = (string) $version['Browser'];

check('dmore/chrome-mink-driver', $text, 'chrome-mink-driver', substr($product, strpos($product, '/') + 1));
