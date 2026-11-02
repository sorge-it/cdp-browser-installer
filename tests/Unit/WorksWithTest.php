<?php

declare(strict_types=1);

// The README shows under "Works with" what the CI job `works-with` runs. These tests keep the two
// equal: an example that the job does not run would be a claim without a proof.

/**
 * The text under the README heading `### <tool>`, up to the next heading.
 */
function readmeSection(string $tool): string
{
    $readme = (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
    $start = strpos($readme, "\n### {$tool}\n");

    if ($start === false) {
        throw new RuntimeException(sprintf('The README has no section `### %s`.', $tool));
    }

    return preg_split('/^#{2,3} /m', substr($readme, $start + 1), 3)[1] ?? '';
}

/**
 * The code blocks of one language in a README section.
 *
 * @return list<string>
 */
function readmeBlocks(string $section, string $language): array
{
    preg_match_all('/^```'.$language.'\n(.*?)^```$/ms', $section, $blocks);

    return $blocks[1];
}

/**
 * The lines of a script between `// README: begin` and `// README: end`, without their indent.
 */
function scriptRegion(string $script): string
{
    if (preg_match('~^( *)// README: begin\n(.*?)^ *// README: end$~ms', $script, $match) !== 1) {
        throw new RuntimeException('The script has no lines between `// README: begin` and `// README: end`.');
    }

    return (string) preg_replace('/^'.$match[1].'/m', '', $match[2]);
}

it('shows each tool with the lines that its script runs, and frees the browser as the script does', function (string $tool, string $file, string $free): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/works-with/'.$file);
    $blocks = readmeBlocks(readmeSection($tool), 'php');
    $region = scriptRegion($script);
    preg_match_all('/^use [^;]+;$/m', $blocks[0] ?? '', $imports);
    $freed = '~\} finally \{\n\s*'.preg_quote($free, '~').'\n~';

    expect($blocks)->toHaveCount(1)
        ->and($region)->not()->toBe('')
        ->and($blocks[0])->toContain($region)
        ->and($imports[0])->not()->toBe([])
        ->and(array_values(array_filter($imports[0], static fn (string $import): bool => ! str_contains($script, $import."\n"))))->toBe([])
        ->and(preg_match($freed, $blocks[0]))->toBe(1)
        ->and(preg_match($freed, $script))->toBe(1);
})->with([
    'chrome-php/chrome' => ['chrome-php/chrome', 'chrome-php.php', '$browser->close();'],
    'dmore/chrome-mink-driver' => ['dmore/chrome-mink-driver', 'chrome-mink-driver.php', '$session->stop();'],
]);

it('starts the browser for dmore/chrome-mink-driver as the command line of the README', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/works-with/chrome-mink-driver.php');
    $section = readmeSection('dmore/chrome-mink-driver');
    $commands = readmeBlocks($section, 'sh');
    preg_match_all('/ (--[a-z-]+=?[0-9]*)/', $commands[0] ?? '', $shown);
    preg_match_all("/'(--[a-z-]+=?[0-9]*)/", $script, $started);

    // --no-sandbox depends on the system, so the README names it in its text, not in the command.
    expect($commands)->toHaveCount(1)
        ->and($commands[0])->toStartWith('"$(vendor/bin/cdp-browser path)" ')
        ->and($script)->toContain("exec('vendor/bin/cdp-browser path'")
        ->and($shown[1])->toBe(['--headless', '--remote-debugging-port=9222', '--user-data-dir='])
        ->and($started[1])->toBe([...$shown[1], '--no-sandbox'])
        ->and($section)->toContain('`--no-sandbox`');
});
