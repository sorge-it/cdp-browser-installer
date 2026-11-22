<?php

declare(strict_types=1);

// The README shows under "Works with" what the workflow `works-with` runs. These tests keep the two
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
    // A complete file, run from the root of the project, as the script runs from works-with/.
    $autoload = "\nrequire __DIR__.'/vendor/autoload.php';\n";

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0])->toStartWith("<?php\n\nuse ")
        ->and($blocks[0])->toContain($autoload)
        ->and($script)->toContain($autoload)
        ->and($region)->not()->toBe('')
        ->and($blocks[0])->toContain($region)
        ->and($imports[0])->not()->toBe([])
        ->and(array_values(array_filter($imports[0], static fn (string $import): bool => ! str_contains($script, $import."\n"))))->toBe([])
        ->and(preg_match($freed, $blocks[0]))->toBe(1)
        ->and(preg_match($freed, $script))->toBe(1);
})->with([
    'chrome-php/chrome' => ['chrome-php/chrome', 'chrome-php.php', '$browser->close();'],
    'dmore/chrome-mink-driver' => ['Mink (dmore/chrome-mink-driver)', 'chrome-mink-driver.php', '$session->stop();'],
    'php-webdriver/webdriver' => ['php-webdriver/webdriver', 'php-webdriver.php', '$driver->quit();'],
    'symfony/panther' => ['Symfony Panther (symfony/panther)', 'panther.php', '$client->quit();'],
]);

it('shows the base test class of each test tool with the methods that the job runs', function (string $tool, string $file, string $class): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/works-with/'.$file);
    $blocks = readmeBlocks(readmeSection($tool), 'php');
    // The methods stand in the class body, one level in.
    $methods = (string) preg_replace('/^(?=.)/m', '    ', scriptRegion($script));
    preg_match_all('/^use [^;]+;$/m', $blocks[0] ?? '', $imports);
    preg_match('/^namespace [^;]+;$/m', $script, $namespace);

    expect($namespace)->toHaveCount(1)
        ->and($blocks)->toHaveCount(1)
        ->and($blocks[0])->toStartWith("<?php\n\n".($namespace[0] ?? '')."\n\nuse ")
        ->and($methods)->toContain("    #[BeforeClass]\n")
        ->and($script)->toContain($class."\n{\n")
        ->and($blocks[0])->toContain($class."\n{\n".$methods."}\n")
        ->and(array_values(array_filter($imports[0], static fn (string $import): bool => ! str_contains($script, $import."\n"))))->toBe([])
        ->and($imports[0])->toHaveCount(substr_count($script, "\nuse "));
})->with([
    'laravel/dusk' => ['Laravel Dusk (laravel/dusk)', 'dusk/DuskTestCase.php', 'abstract class DuskTestCase extends BaseTestCase'],
    'symfony/panther with PantherTestCase' => ['Symfony Panther with PantherTestCase', 'panther/PantherTestCase.php', 'abstract class PantherTestCase extends BaseTestCase'],
]);

it('starts the browser for dmore/chrome-mink-driver as the command line of the README', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2).'/works-with/chrome-mink-driver.php');
    $section = readmeSection('Mink (dmore/chrome-mink-driver)');
    $commands = readmeBlocks($section, 'sh');
    // The first line starts the browser; the second waits until it listens.
    $lines = explode("\n", $commands[0] ?? '');
    preg_match_all('/ (--[a-z-]+=?[0-9]*)/', $lines[0], $shown);
    preg_match_all("/'(--[a-z-]+=?[0-9]*)/", $script, $started);

    // Both add --no-sandbox on Linux only: the README through uname, the script through PHP_OS_FAMILY.
    expect($commands)->toHaveCount(1)
        ->and($lines[1] ?? '')->toEndWith(' http://127.0.0.1:9222/json/version')
        ->and($commands[0])->toStartWith('"$(vendor/bin/cdp-browser path chrome-headless-shell)" ')
        ->and($lines[0])->toEndWith(' $([ "$(uname)" = Linux ] && echo --no-sandbox) &')
        ->and($script)->toContain("exec('vendor/bin/cdp-browser path chrome-headless-shell'")
        ->and($shown[1])->toBe(['--headless', '--remote-debugging-port=9222', '--user-data-dir=', '--no-sandbox'])
        ->and($started[1])->toBe($shown[1]);
});
