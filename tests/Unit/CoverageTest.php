<?php

declare(strict_types=1);

use Pest\TypeCoverage\Support\ConfigurationSourceDetector;

it('leaves no line of src and bin/cdp-browser out of the coverage', function (): void {
    $root = dirname(__DIR__, 2);
    $files = [$root.'/bin/cdp-browser'];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS)) as $file) {
        assert($file instanceof SplFileInfo);
        $files[] = $file->getPathname();
    }

    $marked = array_values(array_filter($files, static fn (string $file): bool => str_contains((string) file_get_contents($file), '@codeCoverageIgnore')));
    $phpunit = (string) file_get_contents($root.'/phpunit.xml');

    // A marker here is a decision: a test reaches each step.
    expect($files)->toContain($root.'/src/Installer.php')
        ->and($marked)->toBe([])
        ->and($phpunit)->not()->toContain('<exclude>')
        ->toContain('<file>bin/cdp-browser</file>');
});

it('names each file of bin/ to PHPStan, which reads only the *.php files of a directory', function (): void {
    $root = dirname(__DIR__, 2);
    $phpstan = (string) file_get_contents($root.'/phpstan.neon');
    $files = array_map(basename(...), glob($root.'/bin/*') ?: []);

    expect($files)->toBe(['cdp-browser']);

    foreach ($files as $file) {
        expect($phpstan)->toContain("        - bin/{$file}\n");
    }
});

it('checks the types of each source directory and of tests', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($composer) && is_array($composer['scripts']) && is_string($composer['scripts']['test:type-coverage']));

    // As the plugin does: the first `--configuration=`, read by its own detector. `-c` and the
    // long option with a space fall back to phpunit.xml without a word.
    $option = current(array_filter(explode(' ', $composer['scripts']['test:type-coverage']), static fn (string $argument): bool => str_starts_with($argument, '--configuration=')));
    $directories = ConfigurationSourceDetector::detect([(string) $option]);
    $files = [];

    foreach ($directories as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            assert($file instanceof SplFileInfo);
            $files[] = $file->getPathname();
        }
    }

    // The plugin skips each file that contains this text.
    $skipped = array_values(array_filter($files, static fn (string $file): bool => str_contains((string) file_get_contents($file), 'trait'.' ')));

    expect($composer['scripts']['test'])->toContain('@test:type-coverage')
        ->and($composer['scripts']['test:type-coverage'])->toContain('vendor/bin/pest --type-coverage --min=100 ')
        ->and($option)->toBe('--configuration=phpunit.type-coverage.xml')
        ->and($directories)->toBe([...ConfigurationSourceDetector::detect([]), realpath($root.'/tests')])
        ->toContain(realpath($root.'/src'))
        ->and($skipped)->toBe([]);
});
