<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * The platforms of Chrome for Testing that the installer supports. The value is the name that
 * Chrome for Testing uses in its download paths.
 */
enum Platform: string
{
    case LinuxX64 = 'linux64';
    case LinuxArm64 = 'linux-arm64';
    case MacX64 = 'mac-x64';
    case MacArm64 = 'mac-arm64';

    /**
     * The name of the binary.
     */
    public const string BINARY = 'chrome-headless-shell';

    /**
     * @param  string  $family  the value of `PHP_OS_FAMILY`
     * @param  string  $machine  the value of `php_uname('m')`
     */
    public static function detect(string $family, string $machine): self
    {
        $architecture = match ($machine) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };

        return match ([$family, $architecture]) {
            ['Linux', 'x64'] => self::LinuxX64,
            ['Linux', 'arm64'] => self::LinuxArm64,
            ['Darwin', 'x64'] => self::MacX64,
            ['Darwin', 'arm64'] => self::MacArm64,
            default => throw new InstallerException(sprintf(
                'There is no chrome-headless-shell for %s on %s. The installer supports %s.',
                $family,
                $machine,
                implode(', ', array_map(static fn (self $platform): string => $platform->value, self::cases())),
            )),
        };
    }

    public static function current(): self
    {
        return self::detect(PHP_OS_FAMILY, php_uname('m'));
    }

    /**
     * The top directory inside the archive.
     */
    public function directory(): string
    {
        return 'chrome-headless-shell-'.$this->value;
    }

    /**
     * The path of the binary inside the archive.
     */
    public function binary(): string
    {
        return $this->directory().'/'.self::BINARY;
    }
}
