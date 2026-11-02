<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * Steps on files of the install. `pin` makes its directory with `makeDirectory()`, checks the pin
 * file through `target()` and writes it with `write()`.
 *
 * @internal
 */
final class Files
{
    /**
     * Two workers may create the same directory at once; only a directory that is still missing
     * afterwards is an error.
     */
    public static function makeDirectory(string $directory): void
    {
        if (is_dir($directory) || @mkdir($directory, 0o755, true)) {
            return;
        }

        // Another worker may have made it since the check.
        if (! is_dir($directory)) {
            throw new InstallerException(sprintf('The directory %s could not be created.', $directory));
        }
    }

    /**
     * The file that `write()` replaces for the path: the target of a symbolic link, so that the
     * link stays.
     */
    public static function target(string $file): string
    {
        $target = is_link($file) ? realpath($file) : false;

        return $target === false ? $file : $target;
    }

    /**
     * A new file of mode 0644, then one rename, so that a reader, such as another run, never sees
     * half of it. A failure leaves no new file.
     */
    public static function write(string $file, string $content): bool
    {
        // A name of its own for each run. In the cache, `Installer::removeLeftovers()` finds it after a run that stopped.
        $temporary = $file.'.'.bin2hex(random_bytes(4));

        // Each caller throws its own message for a step that fails.
        if (@file_put_contents($temporary, $content) !== false && @chmod($temporary, 0o644) && @rename($temporary, $file)) {
            return true;
        }

        if (is_file($temporary)) {
            @unlink($temporary);
        }

        return false;
    }
}
