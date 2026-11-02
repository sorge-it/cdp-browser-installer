<?php

declare(strict_types=1);

use SorgeIt\CdpBrowserInstaller\Platform;

it('detects the platform from the system and the machine', function (string $family, string $machine, Platform $platform): void {
    expect(Platform::detect($family, $machine))->toBe($platform);
})->with([
    ['Linux', 'x86_64', Platform::LinuxX64],
    ['Linux', 'aarch64', Platform::LinuxArm64],
    ['Linux', 'arm64', Platform::LinuxArm64],
    ['Darwin', 'x86_64', Platform::MacX64],
    ['Darwin', 'arm64', Platform::MacArm64],
]);

it('names the supported platforms for any other system', function (string $family, string $machine): void {
    expect(fn (): Platform => Platform::detect($family, $machine))->toThrow(installerException(
        sprintf('There is no chrome-headless-shell for %s on %s. The installer supports linux64, linux-arm64, mac-x64, mac-arm64.', $family, $machine),
    ));
})->with([
    ['Linux', 'ppc64le'],
    ['Windows', 'AMD64'],
    ['BSD', 'amd64'],
]);

it('detects the platform of the running system', function (): void {
    $system = posix_uname();
    assert(is_array($system) && is_string($system['sysname']) && is_string($system['machine']));

    expect(Platform::current())->toBe(Platform::detect($system['sysname'], $system['machine']));
});

it('names the top directory of the archive', function (): void {
    expect(Platform::MacArm64->directory())->toBe('chrome-headless-shell-mac-arm64');
});
