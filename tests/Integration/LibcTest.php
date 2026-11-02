<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Libc;
use SorgeIt\CdpBrowserInstaller\Tests\FakeTools;

beforeEach(function (): void {
    $this->directory = temporaryDirectory();
    $this->tools = new FakeTools($this->directory.'/tools');
});

it('asks getconf, then the maps of the process, then the loader files', function (?string $getconf, ?string $maps, bool $loader, bool $musl): void {
    if ($getconf !== null) {
        $this->tools->add('getconf', $getconf);
    }

    if ($maps !== null) {
        file_put_contents($this->directory.'/maps', $maps);
    }

    if ($loader) {
        touch($this->directory.'/ld-musl-x86_64.so.1');
    }

    expect(Libc::detect($this->tools->commandLine(system: false), 'Linux', $this->directory.'/maps', $this->directory.'/ld-musl-*.so.1'))->toBe($musl)
        ->and($this->tools->calls('getconf'))->toBe($getconf === null ? [] : ['GNU_LIBC_VERSION']);
})->with([
    // The other probes say musl; the answer of getconf decides.
    'getconf answers for glibc' => ['echo "glibc 2.41\n";', "7f00 r-xp /lib/ld-musl-aarch64.so.1\n", true, false],
    'getconf fails, the maps name musl' => ['exit(1);', "7f00 r-xp /lib/ld-musl-aarch64.so.1\n", false, true],
    'only a loader file' => [null, null, true, true],
    'no sign of musl' => [null, null, false, false],
]);

it('takes maps that it cannot read for maps without musl', function (): void {
    // A directory passes is_readable(), and reading it fails.
    mkdir($this->directory.'/maps');

    expect(underErrorExceptions(fn (): bool => Libc::detect($this->tools->commandLine(system: false), 'Linux', $this->directory.'/maps', $this->directory.'/ld-musl-*.so.1')))->toBeFalse();
});

it('takes every system but Linux for one without musl, and asks no tool', function (): void {
    $this->tools->add('getconf', 'exit(1);');
    file_put_contents($this->directory.'/maps', "7f00 r-xp /lib/ld-musl-aarch64.so.1\n");
    touch($this->directory.'/ld-musl-aarch64.so.1');

    expect(Libc::detect($this->tools->commandLine(system: false), 'Darwin', $this->directory.'/maps', $this->directory.'/ld-musl-*.so.1'))->toBeFalse()
        ->and($this->tools->calls('getconf'))->toBe([]);
});
