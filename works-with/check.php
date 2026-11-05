<?php

declare(strict_types=1);

// The checks that each script of the CI job `works-with` makes after its tool read the page.

use Composer\InstalledVersions;
use SorgeIt\CdpBrowserInstaller\Pin;

/**
 * Ends the script with exit code 1 when the tool did not read the expected text, or reached a
 * browser or a ChromeDriver of another version than the pair. Else it prints the result, and adds
 * a row to the summary of the run on GitHub Actions.
 *
 * @param  string  $browser  the version that the browser reports through the tool, such as `157.0.8103.38`
 * @param  string|null  $chromedriver  the `chromedriverVersion` that ChromeDriver reports; `null` for a tool that talks to the browser directly
 */
function check(string $tool, ?string $text, string $expected, string $browser, ?string $chromedriver = null): void
{
    $system = getenv('CDP_BROWSER_CHROME');

    if (is_string($system) && $system !== '') {
        // Such as "Chromium 156.0.8078.79 Alpine Linux".
        preg_match('/ (\d+(?:\.\d+){3})\b/', (string) shell_exec(escapeshellarg($system).' --version'), $match);
        $version = $match[1] ?? '';
        $source = 'the pair of CDP_BROWSER_CHROME';
    } else {
        $version = Pin::read(Pin::FILE)->version;
        $source = 'the pin file';
    }

    $error = match (true) {
        $version === '' => sprintf('`%s --version` names no version.', $system),
        $text === null => sprintf('%s found no element `#ok` on the page.', $tool),
        $text !== $expected => sprintf('%s read `%s` from the page, not `%s`.', $tool, $text, $expected),
        $browser !== $version => sprintf('%s reached a browser of version `%s`, not %s of %s.', $tool, $browser, $version, $source),
        // ChromeDriver reports its version, then the commit it was built from.
        $chromedriver !== null && ! str_starts_with($chromedriver, $version.' ') => sprintf('%s started ChromeDriver `%s`, not %s of %s.', $tool, $chromedriver, $version, $source),
        default => null,
    };

    if ($error !== null) {
        fwrite(STDERR, $error."\n");

        exit(1);
    }

    $release = ltrim(InstalledVersions::getPrettyVersion($tool) ?? 'unknown', 'v');
    $driver = $chromedriver === null ? '' : ' and ChromeDriver '.$version;
    printf("%s %s read the page through the browser %s%s.\n", $tool, $release, $version, $driver);

    $summary = getenv('GITHUB_STEP_SUMMARY');
    $alpine = is_file('/etc/alpine-release') ? ' Alpine '.trim((string) file_get_contents('/etc/alpine-release')) : '';

    if (is_string($summary) && $summary !== '') {
        file_put_contents($summary, sprintf("| `%s` | %s | %s | %s | %s %s%s |\n", $tool, $release, $version, $chromedriver === null ? '–' : $version, PHP_OS_FAMILY, php_uname('m'), $alpine), FILE_APPEND);
    }
}
