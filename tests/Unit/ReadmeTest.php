<?php

declare(strict_types=1);

// The README tagline and the description in composer.json are one text, which Packagist and the
// repository page show as well.

it('states the tagline of the README word for word as the description in composer.json', function (): void {
    $root = dirname(__DIR__, 2);
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    preg_match('/^\*\*([^*]+)\*\*$/m', (string) file_get_contents($root.'/README.md'), $tagline);

    expect($tagline[1] ?? null)->toBe(is_array($composer) ? $composer['description'] ?? null : null)
        ->and($tagline[1] ?? '')->toStartWith('Installs ');
});
