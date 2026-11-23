<?php

declare(strict_types=1);

it('names each directory with a Dockerfile to Dependabot, and none under .github', function (): void {
    $root = dirname(__DIR__, 2);
    $config = (string) file_get_contents($root.'/.github/dependabot.yml');
    $start = strpos($config, "  - package-ecosystem: docker\n");
    assert(is_int($start));
    preg_match('/^    directories:\n((?:      - \S+\n)+)/m', substr($config, $start), $match);
    $directories = array_map(static fn (string $line): string => substr($line, 8), explode("\n", trim($match[1] ?? '', "\n")));

    $dockerfiles = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $file): bool => ! in_array($file->getFilename(), ['.cache', '.git', '.tmp', 'vendor'], true),
    ));

    foreach ($iterator as $file) {
        assert($file instanceof SplFileInfo);

        if ($file->getFilename() === 'Dockerfile') {
            $dockerfiles[] = substr($file->getPath(), strlen($root));
        }
    }

    sort($dockerfiles);

    // GitHub refuses a pull request of the docker ecosystem that changes a file under .github.
    expect(array_values(array_filter($directories, static fn (string $directory): bool => str_starts_with($directory, '/.github'))))->toBe([])
        ->and($directories)->toBe($dockerfiles)
        ->toContain('/demo', '/docker/composer');
});

it('leaves the Dockerfiles out of the package', function (): void {
    $attributes = (string) file_get_contents(dirname(__DIR__, 2).'/.gitattributes');

    expect($attributes)->toContain("\n/docker export-ignore\n", "\n/demo export-ignore\n");
});
