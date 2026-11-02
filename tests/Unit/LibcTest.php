<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Libc;

it('tells musl from glibc', function (?string $getconf, string $maps, ?string $loader, bool $musl): void {
    expect(Libc::isMusl($getconf, $maps, $loader === null ? [] : [$loader]))->toBe($musl);
})->with([
    'glibc' => ["glibc 2.41\n", "7f00 r-xp /usr/lib/x86_64-linux-gnu/libc.so.6\n", null, false],
    // Debian with the package `musl` has the loader file, and getconf still answers for glibc.
    'glibc beside a musl package' => ["glibc 2.41\n", '', '/lib/ld-musl-x86_64.so.1', false],
    'Alpine' => [null, "7f00 r-xp /lib/ld-musl-aarch64.so.1\n", '/lib/ld-musl-aarch64.so.1', true],
    'a static PHP on Alpine' => [null, '', '/lib/ld-musl-x86_64.so.1', true],
    'musl with its loader in another directory' => [null, "7f00 r-xp /usr/lib/ld-musl-x86_64.so.1\n", null, true],
    'no getconf and no musl' => [null, '', null, false],
]);
