<?php

declare(strict_types=1);

// The checks that each script of the CI job `works-with` makes after its tool read the page.

use Composer\InstalledVersions;
use SorgeIt\CdpBrowserInstaller\Pin;

/**
 * Ends the script with exit code 1 when the tool did not read the expected text, or reached a
 * browser or a ChromeDriver of another version than the pin file. Else it prints the result, and
 * adds a row to the summary of the run on GitHub Actions.
 *
 * @param  string|null  $chromedriver  the `chromedriverVersion` that ChromeDriver reports; `null` for a tool that talks to the browser directly
 */
function check(string $tool, ?string $text, string $expected, string $agent, ?string $chromedriver = null): void
{
    $version = Pin::read(Pin::FILE)->version;
    $browser = 'HeadlessChrome/'.$version;
    $error = match (true) {
        $text === null => sprintf('%s found no element `#ok` on the page.', $tool),
        $text !== $expected => sprintf('%s read `%s` from the page, not `%s`.', $tool, $text, $expected),
        ! str_contains($agent, $browser.' ') => sprintf('%s reached `%s`, not %s of the pin file.', $tool, $agent, $browser),
        // ChromeDriver reports its version, then the commit it was built from.
        $chromedriver !== null && ! str_starts_with($chromedriver, $version.' ') => sprintf('%s started ChromeDriver `%s`, not %s of the pin file.', $tool, $chromedriver, $version),
        default => null,
    };

    if ($error !== null) {
        fwrite(STDERR, $error."\n");

        exit(1);
    }

    $release = ltrim(InstalledVersions::getPrettyVersion($tool) ?? 'unknown', 'v');
    $driver = $chromedriver === null ? '' : ' and ChromeDriver '.$version;
    printf("%s %s read the page through %s%s.\n", $tool, $release, $browser, $driver);

    $summary = getenv('GITHUB_STEP_SUMMARY');

    if (is_string($summary) && $summary !== '') {
        file_put_contents($summary, sprintf("| `%s` | %s | %s | %s | %s %s |\n", $tool, $release, $browser, $chromedriver === null ? '–' : $version, PHP_OS_FAMILY, php_uname('m')), FILE_APPEND);
    }
}
