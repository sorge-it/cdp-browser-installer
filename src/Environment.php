<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * Checks the values that `Installer` reads from the environment: the cache directory and the
 * seconds; also the temporary directory of `pin`.
 *
 * @internal
 */
final class Environment
{
    /**
     * The tools with the timeout of `CDP_BROWSER_TIMEOUT`, if it is set.
     *
     * @param  array<string, string>  $environment
     */
    public static function applyTimeout(array $environment, CommandLine $commandLine): CommandLine
    {
        $timeout = self::seconds($environment, Installer::TIMEOUT, CommandLine::SECONDS);

        return $timeout === null ? $commandLine : $commandLine->withTimeout($timeout);
    }

    /**
     * The cache directory of `Installer::cache()`. A relative `XDG_CACHE_HOME` is ignored, as the
     * XDG Base Directory Specification asks.
     *
     * @param  array<string, string>  $environment
     */
    public static function cache(array $environment): string
    {
        $override = $environment['CDP_BROWSER_PATH'] ?? '';

        if ($override !== '') {
            return self::cacheDirectory(sprintf('CDP_BROWSER_PATH `%s`', $override), self::absolute('CDP_BROWSER_PATH', $override));
        }

        $base = $environment['XDG_CACHE_HOME'] ?? '';

        if (str_starts_with($base, '/')) {
            return self::cacheDirectory(sprintf('XDG_CACHE_HOME `%s`', $base), $base.'/cdp-browser');
        }

        $home = $environment['HOME'] ?? '';

        if ($home === '') {
            throw new InstallerException('Neither CDP_BROWSER_PATH, an absolute XDG_CACHE_HOME nor HOME is set, so there is no cache directory.');
        }

        return self::cacheDirectory(sprintf('HOME `%s`', $home), self::absolute('HOME', $home).'/.cache/cdp-browser');
    }

    /**
     * The cache directory that `Installer` takes, with `.` and `..` resolved: absolute, not the
     * root, and without a character that safe-unzip refuses in the path of the archive, also behind
     * a symbolic link. So such a path stops before the download.
     *
     * @param  string  $name  the value it came from, for the message, such as "CDP_BROWSER_PATH `/`"
     */
    public static function cacheDirectory(string $name, string $path): string
    {
        if (! str_starts_with($path, '/')) {
            throw new InstallerException(sprintf('%s is not an absolute path. Use the full path.', $name));
        }

        $cache = self::resolve($path);

        // Each version would become a directory of the root.
        if ($cache === '/') {
            throw new InstallerException(sprintf('%s is the root directory. Use a separate directory for the cache.', $name));
        }

        // As safe-unzip checks the real path of an archive: a symbolic link may lead to such a name.
        self::checkCharacters(array_values(array_unique([$cache, self::real($cache)])), 'The cache directory', 'Use a path without them.');

        return $cache;
    }

    /**
     * The temporary directory of `pin`, from `sys_get_temp_dir()`, whose real path holds no
     * character that safe-unzip refuses in the real path of an archive. A relative one counts
     * from the working directory, as before.
     *
     * @param  string|null  $directory  `null` for `sys_get_temp_dir()`, which PHP reads once per process
     */
    public static function temporaryDirectory(?string $directory = null): string
    {
        $directory ??= sys_get_temp_dir();

        // PHP strips the trailing slash, so TMPDIR=/ gives an empty value.
        if ($directory === '') {
            return '/';
        }

        if (! str_starts_with($directory, '/')) {
            $directory = (getcwd() ?: '.').'/'.$directory;
        }

        // Only the real path, as safe-unzip checks it: a link or `..` may lead past such a name.
        self::checkCharacters([self::real($directory)], 'The temporary directory', 'Set TMPDIR, or sys_temp_dir in php.ini, to a path without them.');

        return $directory;
    }

    /**
     * A whole number of seconds above 0, in the text a shell or a Dockerfile writes; `null` for a
     * variable that is not set or empty.
     *
     * @param  array<string, string>  $environment
     * @param  int  $example  the default, for the message
     */
    public static function seconds(array $environment, string $variable, int $example): ?int
    {
        $value = $environment[$variable] ?? '';

        if ($value === '') {
            return null;
        }

        $seconds = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        // `filter_var()` also takes spaces, a sign and leading zeros around the number.
        if (! is_int($seconds) || (string) $seconds !== $value) {
            throw new InstallerException(sprintf('%s `%s` is not a whole number of seconds above 0, such as %d.', $variable, $value, $example));
        }

        return $seconds;
    }

    /**
     * Stops at a path with a character that safe-unzip refuses in the real path of an archive.
     *
     * @param  list<string>  $paths  the path of a directory, its real path, or both
     * @param  string  $label  what the directory is, for the message
     * @param  string  $next  the next step, for the message
     */
    private static function checkCharacters(array $paths, string $label, string $next): void
    {
        foreach ($paths as $checked) {
            if (strpbrk($checked, "*?[]\\\n") !== false) {
                throw new InstallerException(sprintf('%s %s holds `*`, `?`, `[`, `]`, `\\` or a line break, which unzip reads as a pattern or zipinfo prints as two lines. %s', $label, $checked, $next));
            }
        }
    }

    /**
     * The path from the working directory if it is relative. `cacheDirectory()` resolves it.
     */
    private static function absolute(string $variable, string $path): string
    {
        // Only a shell expands `~`; a `.env` file or the `ENV` of a Dockerfile does not.
        if (str_starts_with($path, '~')) {
            throw new InstallerException(sprintf('%s `%s` starts with `~`, which only a shell expands. Use the full path.', $variable, $path));
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        $directory = getcwd();

        if ($directory === false) {
            throw new InstallerException(sprintf('%s `%s` is relative, and the working directory is gone.', $variable, $path));
        }

        return $directory.'/'.$path;
    }

    /**
     * Resolves `.` and `..` from the text alone, without following symbolic links: the directory
     * may not exist yet, so `realpath()` cannot resolve it.
     */
    private static function resolve(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return '/'.implode('/', $segments);
    }

    /**
     * The real path of the longest part that exists, and the rest of the path after it; the path
     * as text where no part resolves.
     */
    private static function real(string $path): string
    {
        $rest = '';

        // Under `open_basedir`, `realpath()` warns and gives `false` even for `/`.
        while (($real = Files::quietly(static fn (): string|false => realpath($path))) === false) {
            if (dirname($path) === $path) {
                return $rest;
            }

            $rest = '/'.basename($path).$rest;
            $path = dirname($path);
        }

        return rtrim($real, '/').$rest;
    }
}
