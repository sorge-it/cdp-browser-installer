<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * Whether Linux runs on musl, as Alpine does. Chrome for Testing is built against glibc and does
 * not start there. The order follows `detect-libc`: `getconf` first, then the loader in the maps
 * of this process, then the loader file.
 *
 * @internal
 */
final class Libc
{
    /**
     * The message of `install` and `path` on musl, with the two steps to the system's pair.
     */
    public const string REFUSAL = "Chrome for Testing is built against glibc and does not start on musl, as on Alpine. Install the system's pair (`cdp-browser deps` runs `apk add chromium chromium-chromedriver`), then set ".Installer::OWN_CHROME.' to the browser, such as /usr/bin/chromium, and '.Installer::OWN_CHROMEDRIVER.' to the chromedriver, such as /usr/bin/chromedriver.';

    /**
     * @param  string|null  $getconf  the output of `getconf GNU_LIBC_VERSION`, `null` where it failed
     * @param  string  $maps  the content of `/proc/self/maps`
     * @param  list<string>  $loaders  the files `/lib/ld-musl-*.so.1`
     */
    public static function isMusl(?string $getconf, string $maps, array $loaders): bool
    {
        if ($getconf !== null && str_starts_with($getconf, 'glibc ')) {
            return false;
        }

        return str_contains($maps, '/ld-musl-') || $loaders !== [];
    }

    /**
     * @param  string  $family  the `PHP_OS_FAMILY` to judge
     * @param  string  $maps  the file with the maps of this process
     * @param  string  $loaders  the glob pattern of the loader files
     */
    public static function detect(
        CommandLine $commandLine,
        string $family = PHP_OS_FAMILY,
        string $maps = '/proc/self/maps',
        string $loaders = '/lib/ld-musl-*.so.1',
    ): bool {
        if ($family !== 'Linux') {
            return false;
        }

        try {
            $getconf = $commandLine->run(['getconf', 'GNU_LIBC_VERSION']);
        } catch (InstallerException) {
            // musl knows no GNU_LIBC_VERSION, and a minimal image may have no getconf.
            $getconf = null;
        }

        return self::isMusl($getconf, is_readable($maps) ? (string) Files::quietly(static fn (): string|false => file_get_contents($maps)) : '', glob($loaders) ?: []);
    }
}
