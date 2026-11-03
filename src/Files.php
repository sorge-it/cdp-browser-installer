<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use Closure;

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
        if (is_dir($directory) || self::quietly(static fn (): bool => mkdir($directory, 0o755, true))) {
            return;
        }

        // Another worker may have made it since the check.
        if (! is_dir($directory)) {
            throw new InstallerException(sprintf('The directory %s could not be created.', $directory));
        }
    }

    /**
     * The file that `write()` replaces for the path: the target of a symbolic link, so that the
     * link stays. A link to a missing target is an error, not a file to replace.
     */
    public static function target(string $file): string
    {
        if (! is_link($file)) {
            return $file;
        }

        $target = self::quietly(static fn (): string|false => realpath($file));

        if ($target === false) {
            throw new InstallerException(sprintf('The symbolic link %s could not be resolved. Remove the link, or create its target.', $file));
        }

        return $target;
    }

    /**
     * Runs a call of the file system whose failure the caller judges from its result or from the
     * file system, as when another run moved the same path first. No error handler sees its
     * warning: PHPUnit receives a warning that `@` hides, and Pest prints it.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public static function quietly(Closure $call): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
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
        if (self::quietly(static fn (): bool => file_put_contents($temporary, $content) !== false && chmod($temporary, 0o644) && rename($temporary, $file))) {
            return true;
        }

        if (is_file($temporary)) {
            self::quietly(static fn (): bool => unlink($temporary));
        }

        return false;
    }
}
