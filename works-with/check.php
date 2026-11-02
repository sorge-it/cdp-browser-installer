<?php

declare(strict_types=1);

// The checks that each script of the CI job `works-with` makes after its tool read the page.

use Composer\InstalledVersions;
use SorgeIt\CdpBrowserInstaller\Pin;

/**
 * Ends the script with exit code 1 when the tool did not read the expected text, or reached a
 * browser of another version than the pin file. Else it prints the result, and adds a row to the
 * summary of the run on GitHub Actions.
 */
function check(string $tool, ?string $text, string $expected, string $agent): void
{
    $browser = 'HeadlessChrome/'.Pin::read(Pin::FILE)->version;
    $error = match (true) {
        $text === null => sprintf('%s found no element `#ok` on the page.', $tool),
        $text !== $expected => sprintf('%s read `%s` from the page, not `%s`.', $tool, $text, $expected),
        ! str_contains($agent, $browser.' ') => sprintf('%s reached `%s`, not %s of the pin file.', $tool, $agent, $browser),
        default => null,
    };

    if ($error !== null) {
        fwrite(STDERR, $error."\n");

        exit(1);
    }

    $version = ltrim(InstalledVersions::getPrettyVersion($tool) ?? 'unknown', 'v');
    printf("%s %s read the page through %s.\n", $tool, $version, $browser);

    $summary = getenv('GITHUB_STEP_SUMMARY');

    if (is_string($summary) && $summary !== '') {
        file_put_contents($summary, sprintf("| `%s` | %s | %s | %s %s |\n", $tool, $version, $browser, PHP_OS_FAMILY, php_uname('m')), FILE_APPEND);
    }
}
