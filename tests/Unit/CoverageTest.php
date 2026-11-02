<?php

declare(strict_types=1);

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
