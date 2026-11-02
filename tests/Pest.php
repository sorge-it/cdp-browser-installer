<?php

declare(strict_types=1);

use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\RawCodeCoverageData;
use SorgeIt\CdpBrowserInstaller\ChecksumException;
use SorgeIt\CdpBrowserInstaller\DownloadException;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;
use SorgeIt\CdpBrowserInstaller\Tests\TestCase;
use Symfony\Component\Process\Process;

pest()->extend(TestCase::class)->in('Unit', 'Integration');

/**
 * A closure of a dataset, as it is. Pest, or the test through `call($this)`, runs it on the test
 * case. For PHPStan, which cannot see that binding, `$this` in it is TestCase.
 *
 * @param-closure-this TestCase  $closure
 */
function onTestCase(Closure $closure): Closure
{
    return $closure;
}

/**
 * For `toThrow()`: an exception of exactly the class, whose message contains the text, and whose
 * previous exception, if given, is of that class. Pest ignores what the closure returns, so it
 * checks with `expect()`. Pest reads the class from the type of the parameter.
 *
 * @param  class-string<InstallerException>  $class
 * @param  class-string<Throwable>|null  $previous
 */
function thrown(string $class, string $message = '', ?string $previous = null): Closure
{
    return static function (InstallerException $exception) use ($class, $message, $previous): void {
        expect($exception::class)->toBe($class)
            ->and($exception->getMessage())->toContain($message);

        if ($previous !== null) {
            expect($exception->getPrevious())->toBeInstanceOf($previous);
        }
    };
}

/**
 * @param  class-string<Throwable>|null  $previous
 */
function installerException(string $message = '', ?string $previous = null): Closure
{
    return thrown(InstallerException::class, $message, $previous);
}

/**
 * @param  class-string<Throwable>|null  $previous
 */
function downloadException(string $message = '', ?string $previous = null): Closure
{
    return thrown(DownloadException::class, $message, $previous);
}

function checksumException(string $message = ''): Closure
{
    return thrown(ChecksumException::class, $message);
}

/**
 * A new empty directory below `.tmp/tests`, removed after the test.
 */
function temporaryDirectory(): string
{
    // Without `..`: the installer resolves it in the cache directory and prints the result.
    $root = dirname(__DIR__);
    $directory = $root.'/.tmp/tests/'.bin2hex(random_bytes(6));
    mkdir($directory, 0o755, true);
    register_shutdown_function(static fn () => removeDirectory($directory));

    return $directory;
}

/**
 * @return list<string> the directory of each run of `pin`, from the `--output` of the fake curl
 */
function pinDirectories(FakeTools $tools): array
{
    $pattern = '~ --output ('.preg_quote(sys_get_temp_dir().'/cdp-browser-pin-', '~').'[0-9a-f]{16})/archive\.zip ~';
    $directories = [];

    foreach ($tools->calls('curl') as $call) {
        if (preg_match($pattern, $call, $match) === 1) {
            $directories[] = $match[1];
        }
    }

    return array_values(array_unique($directories));
}

function removeDirectory(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    if (! is_dir($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        removeDirectory($path.'/'.$entry);
    }

    rmdir($path);
}

/**
 * Writes a ZIP archive with Unix modes, as Chrome for Testing builds them.
 *
 * @param  array<string, array{string, int}>  $entries  the content and the mode of each name
 */
function zipArchive(string $file, array $entries): string
{
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => [$content, $mode]) {
        $zip->addFromString($name, $content);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
    }

    $zip->close();

    return $file;
}

/**
 * The binary in a test archive: it prints its name and its arguments.
 */
const BROWSER = "#!/bin/sh\necho chrome-headless-shell \"\$@\"\n";

/**
 * An archive of chrome-headless-shell for the platform.
 */
function browserArchive(string $file, Platform $platform): string
{
    return zipArchive($file, [
        $platform->directory().'/chrome-headless-shell' => [BROWSER, 0o100755],
        $platform->directory().'/icudtl.dat' => ['data', 0o100644],
    ]);
}

/**
 * A pin whose hash for every platform is the hash of the file.
 */
function pinFor(string $version, string $archive): Pin
{
    $hash = (string) hash_file('sha256', $archive);

    return new Pin($version, array_fill_keys(array_map(static fn (Platform $platform): string => $platform->value, Platform::cases()), $hash));
}

/**
 * Runs PHP code in a new process with the autoloader. Under a coverage run with pcov, the lines
 * the process ran count for the test; under Xdebug they do not, so `composer test:coverage` needs
 * pcov.
 *
 * @param  array<string, string|false>  $environment  added to the environment of the process; `false` removes a variable
 * @param  (Closure(): void)|null  $meanwhile  runs while the process runs
 * @param  list<string>  $settings  more options of PHP, such as `-d disable_functions=…`
 * @return array{string, int} what the process printed, and its exit code
 */
function php(string $code, array $environment = [], ?Closure $meanwhile = null, array $settings = []): array
{
    $root = dirname(__DIR__);
    $coverage = extension_loaded('pcov') && ini_get('pcov.enabled') === '1' && CodeCoverage::instance()->isActive() ? temporaryDirectory().'/coverage' : null;
    $collect = $coverage === null ? '' : sprintf('\pcov\start(); register_shutdown_function(static function (): void { \pcov\stop(); file_put_contents(%s, serialize(\pcov\collect())); });', var_export($coverage, true));
    // pcov takes `src` by default, and `bin/cdp-browser` lies outside it.
    $options = $coverage === null ? [] : ['-d', 'pcov.directory='.$root];
    $process = new Process([PHP_BINARY, ...$options, ...$settings, '-r', $collect.'require '.var_export($root.'/vendor/autoload.php', true).'; '.$code], $root, $environment);
    $process->start();

    if ($meanwhile instanceof Closure) {
        $meanwhile();
    }

    $exitCode = $process->wait();
    $output = $process->getOutput().$process->getErrorOutput();

    if ($coverage !== null) {
        if (! is_file($coverage)) {
            throw new LogicException('The PHP process of the test wrote no coverage, such as after a crash. It printed: '.$output);
        }

        $lines = unserialize((string) file_get_contents($coverage));
        assert(is_array($lines));
        // Both classes are internal to PHPUnit and php-code-coverage. Only a coverage run reaches
        // them, as the CI job on composer.lock.
        /** @var array<non-empty-string, array<positive-int, int>> $lines */
        CodeCoverage::instance()->codeCoverage()->append(RawCodeCoverageData::fromLineCoverage($lines));
    }

    return [$output, $exitCode];
}

/**
 * The error handler of `underErrorExceptions()` as code for a process of `php()`.
 */
const ERROR_EXCEPTIONS = 'set_error_handler(static function (int $level, string $message): bool { if ((error_reporting() & $level) !== 0) { throw new \ErrorException($message, 0, $level); } return true; });';

/**
 * Runs a step under an error handler as Laravel's: a warning or notice without `@` becomes an
 * `ErrorException`, so the installer must throw its own message for a failed call of the file
 * system. A warning behind `@` is dropped; Pest would show it.
 */
function underErrorExceptions(Closure $step): mixed
{
    // PHPUnit lowers the level during a test; an application reports each error.
    $reporting = error_reporting(E_ALL);
    set_error_handler(static function (int $level, string $message, string $file, int $line): bool {
        // Within `@`, `error_reporting()` leaves out warnings and notices.
        if ((error_reporting() & $level) === 0) {
            return true;
        }

        throw new ErrorException($message, 0, $level, $file, $line);
    });

    try {
        return $step();
    } finally {
        restore_error_handler();
        error_reporting($reporting);
    }
}
