<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use Closure;
use LogicException;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * Puts chrome-headless-shell under `<cache>/<version>/<platform>/`, or uses a browser the system
 * brings. The archive stays beside the browser. Its SHA-256 is checked before it is unpacked.
 */
final readonly class Installer
{
    public const string OWN_BROWSER = 'CDP_BROWSER_BINARY';

    /**
     * The seconds of each tool, such as the whole download by curl.
     */
    public const string TIMEOUT = 'CDP_BROWSER_TIMEOUT';

    /**
     * The seconds an install waits for another one in the same directory.
     */
    public const string LOCK_TIMEOUT = 'CDP_BROWSER_LOCK_TIMEOUT';

    /**
     * A file beside the browser that holds the SHA-256 of the archive it was unpacked from.
     */
    private const string STAMP = '.sha256';

    /**
     * The seconds after which a leftover counts as one of a stopped run; curl stops below 1 KiB/s
     * for 60 s. A `.unpack-*` directory gets a new mtime only when made, at its staging directory
     * and at the move. Without a working `flock()`, a second run can remove it during a slow check.
     */
    private const int STOPPED = 120;

    private const int LOCK_SECONDS = 600;

    private ?string $cache;

    private bool $musl;

    /**
     * @param  string|null  $cache  an absolute directory, not the root, without a character that unzip reads as a pattern; `null` for an installer that only checks the system's browser
     * @param  string|null  $ownBrowser  the system's browser, such as Alpine's chromium; then nothing is downloaded
     * @param  bool|null  $musl  whether Linux runs on musl, where Chrome for Testing does not start; `null` detects it
     * @param  int  $lockTimeout  how many seconds an install waits for another one in the same directory; `0` not to wait
     */
    public function __construct(
        private CommandLine $commandLine,
        ?string $cache,
        private ?string $ownBrowser = null,
        ?bool $musl = null,
        private int $lockTimeout = self::LOCK_SECONDS,
    ) {
        if ($lockTimeout < 0) {
            throw new InstallerException(sprintf('The lock timeout is %d seconds. Use 0 for no wait, or a positive value.', $lockTimeout));
        }

        $this->cache = $cache === null ? null : Environment::cacheDirectory(sprintf('The cache directory `%s`', $cache), $cache);
        $this->musl = $musl ?? Libc::detect($commandLine);
    }

    /**
     * The installer as the environment configures it: `CDP_BROWSER_BINARY`, `CDP_BROWSER_PATH`,
     * `XDG_CACHE_HOME`, `HOME`, `CDP_BROWSER_TIMEOUT` and `CDP_BROWSER_LOCK_TIMEOUT`. A test
     * tool starts here.
     *
     * @param  array<string, string>  $environment
     * @param  CommandLine  $commandLine  its timeout is the default of `CDP_BROWSER_TIMEOUT`
     */
    public static function fromEnvironment(array $environment, CommandLine $commandLine = new CommandLine, ?bool $musl = null): self
    {
        $own = $environment[self::OWN_BROWSER] ?? '';
        $commandLine = Environment::applyTimeout($environment, $commandLine);

        // The system's browser needs no cache directory, and so no HOME, and takes no lock.
        if ($own !== '') {
            return new self($commandLine, null, $own, $musl);
        }

        return new self($commandLine, self::cache($environment), null, $musl, Environment::seconds($environment, self::LOCK_TIMEOUT, self::LOCK_SECONDS) ?? self::LOCK_SECONDS);
    }

    /**
     * The cache directory, absolute: `CDP_BROWSER_PATH`, else `cdp-browser` under an absolute
     * `XDG_CACHE_HOME`, else under `~/.cache`. A relative `CDP_BROWSER_PATH` or `HOME` is taken
     * from the working directory; a relative `XDG_CACHE_HOME` is ignored.
     *
     * @param  array<string, string>  $environment
     */
    public static function cache(array $environment): string
    {
        return Environment::cache($environment);
    }

    public function ownBrowser(): ?string
    {
        return $this->ownBrowser;
    }

    /**
     * Whether Linux runs on musl, where `install` refuses: `path` gives the same message.
     *
     * @internal
     */
    public function onMusl(): bool
    {
        return $this->musl;
    }

    /**
     * The browser to start: the system's browser, checked, or the pinned one, installed if missing.
     * One call for a test tool.
     *
     * @param  (Closure(string): void)|null  $onDownload  gets the URL before each download
     */
    public function browser(string $pinFile, Platform $platform, ?Closure $onDownload = null): string
    {
        return $this->ownBrowser === null ? $this->install(Pin::read($pinFile), $platform, $onDownload) : $this->checkOwnBrowser($this->ownBrowser);
    }

    public function binary(Pin $pin, Platform $platform): string
    {
        return $this->directory($pin, $platform).'/'.$platform->binary();
    }

    public function isInstalled(Pin $pin, Platform $platform): bool
    {
        $stamp = $this->directory($pin, $platform).'/'.self::STAMP;

        return is_file($this->binary($pin, $platform))
            && is_file($stamp)
            && @file_get_contents($stamp) === $pin->hash($platform);
    }

    /**
     * Unpacks the archive if the browser is missing or came from another archive. A new download
     * and an archive in the cache are each checked once, in a file of this run, and unpacked from
     * that file. An installed browser needs no lock, and so no write access to the cache.
     *
     * @param  (Closure(string): void)|null  $onDownload  gets the URL before each download
     * @return string the path of the binary
     */
    public function install(Pin $pin, Platform $platform, ?Closure $onDownload = null): string
    {
        if ($this->musl) {
            throw new InstallerException(Libc::REFUSAL);
        }

        if ($this->isInstalled($pin, $platform)) {
            return $this->binary($pin, $platform);
        }

        $directory = $this->directory($pin, $platform);
        $archive = $directory.'/'.$platform->directory().'.zip';
        Files::makeDirectory($directory);
        $lock = $this->lock($directory);

        try {
            $this->removeLeftovers($directory, basename($archive));

            if (! $this->isInstalled($pin, $platform)) {
                $this->installArchive($archive, $directory, $pin, $platform, $onDownload);
            }
        } finally {
            $lock->flock(LOCK_UN);
        }

        return $this->binary($pin, $platform);
    }

    /**
     * Removes what a run leaves when it ends before its `finally`, as after SIGKILL: a download, a
     * temporary or an old tree, a new stamp. An old tree of a run that still runs goes either way.
     */
    private function removeLeftovers(string $directory, string $archive): void
    {
        $pattern = '/\A(?:'.preg_quote($archive, '/').'\.part-|\.unpack-|\.old-|'.preg_quote(self::STAMP, '/').'\.)[0-9a-f]{8}\z/';

        foreach (@scandir($directory) ?: [] as $entry) {
            $path = $directory.'/'.$entry;

            // Another run without a working lock may remove the same path first.
            if (preg_match($pattern, $entry) !== 1 || (int) @filemtime($path) >= time() - self::STOPPED) {
                continue;
            }

            if (is_dir($path) && ! is_link($path)) {
                $this->commandLine->run(['rm', '-rf', '--', $path]);
            } elseif (! @unlink($path) && file_exists($path)) {
                throw new InstallerException(sprintf('The leftover %s could not be removed.', $path));
            }
        }
    }

    private function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            throw new InstallerException(sprintf('%s could not be moved to %s.', $from, $to));
        }
    }

    /**
     * Checks and unpacks the archive in a file of this run, then puts it back for the cache.
     * Without a working `flock()`, as on a bind mount of Docker Desktop, a second run can put
     * another archive at the path of the cache meanwhile; this run still unpacks what it checked.
     *
     * @param  (Closure(string): void)|null  $onDownload
     */
    private function installArchive(string $archive, string $directory, Pin $pin, Platform $platform, ?Closure $onDownload): void
    {
        $own = $archive.'.part-'.bin2hex(random_bytes(4));

        try {
            if (is_file($archive) && @rename($archive, $own)) {
                // A second run without a working lock removes a file of this name by its age, and
                // a rename keeps the age of the file that a cache restored.
                touch($own);
                $this->verify($own, $archive, $pin->hash($platform));
            } elseif (file_exists($archive)) {
                throw new InstallerException(sprintf('%s could not be moved to %s.', $archive, $own));
            } else {
                if ($onDownload instanceof Closure) {
                    $onDownload(Download::url($pin->version, $platform));
                }

                new Download($this->commandLine)->to($pin->version, $platform, $own);
                $this->verify($own, $archive, $pin->hash($platform));
            }

            try {
                $this->unpack($own, $directory, $pin, $platform);
            } catch (Throwable $throwable) {
                // The checked archive stays in the cache, also when its unpack fails.
                @rename($own, $archive);

                throw $throwable;
            }

            $this->move($own, $archive);

            if (! Files::write($directory.'/'.self::STAMP, $pin->hash($platform))) {
                throw new InstallerException(sprintf('The stamp in %s could not be written.', $directory));
            }
        } finally {
            // A failed removal does not replace the first error; the next run removes the file.
            if (is_file($own)) {
                @unlink($own);
            }
        }
    }

    private function directory(Pin $pin, Platform $platform): string
    {
        $cache = $this->cache ?? throw new InstallerException("This installer has no cache directory: it was made for the system's browser.");

        return $cache.'/'.$pin->version.'/'.$platform->value;
    }

    /**
     * The system's browser must be an absolute path to a program that answers `--version`.
     */
    private function checkOwnBrowser(string $browser): string
    {
        if (! str_starts_with($browser, '/')) {
            throw new InstallerException(sprintf('%s must be an absolute path, not `%s`.', self::OWN_BROWSER, $browser));
        }

        // Before `run()`: its message for a missing tool names `deps`, which installs no browser at this path.
        if (! $this->commandLine->has($browser)) {
            throw new InstallerException(sprintf('%1$s `%2$s` is not an executable file. Set %1$s to the path of an existing browser binary, such as /usr/bin/chromium.', self::OWN_BROWSER, $browser));
        }

        try {
            $this->commandLine->run([$browser, '--version']);
        } catch (InstallerException $installerException) {
            throw new InstallerException(sprintf('%s `%s` does not answer --version. %s', self::OWN_BROWSER, $browser, $installerException->getMessage()), $installerException->getPrevious());
        }

        return $browser;
    }

    /**
     * One install per directory at a time: parallel test workers and CI jobs share a cache. `e`:
     * no tool that the run starts inherits the lock, so a killed run frees it.
     */
    private function lock(string $directory): SplFileObject
    {
        $file = $directory.'/.lock';

        try {
            $lock = new SplFileObject($file, 'ce');
        } catch (LogicException|RuntimeException $exception) {
            // A `LogicException` for a directory, a `RuntimeException` for a file it cannot open.
            throw new InstallerException(sprintf('The lock file %s could not be opened.', $file), $exception);
        }

        $deadline = microtime(true) + $this->lockTimeout;

        while (! $lock->flock(LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                throw new InstallerException(sprintf('Another install in %s held the lock for %d seconds.', $directory, $this->lockTimeout));
            }

            usleep(200_000);
        }

        return $lock;
    }

    /**
     * Checks the file of this run. The message names the archive of the cache, which the file
     * came from or would have become; `installArchive()` removes the file.
     */
    private function verify(string $file, string $archive, string $expected): void
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new InstallerException(sprintf('The archive %s could not be read.', $archive));
        }

        $context = hash_init('sha256');

        try {
            // Blocks of 1 MiB.
            while (($block = fread($handle, 1_048_576)) !== false && $block !== '') {
                hash_update($context, $block);
            }
        } finally {
            fclose($handle);
        }

        $actual = hash_final($context);

        if ($actual !== $expected) {
            throw new ChecksumException(sprintf('The SHA-256 of %s is %s, the pin expects %s. The archive is deleted and nothing was unpacked.', $archive, $actual, $expected));
        }
    }

    /**
     * Unpacks the checked archive into a new directory beside the target. The old browser moves
     * aside, the new one moves in, then the old one is removed. A browser that still runs from
     * the old tree keeps only the files it has open.
     */
    private function unpack(string $archive, string $directory, Pin $pin, Platform $platform): void
    {
        $suffix = bin2hex(random_bytes(4));
        $temporary = $directory.'/.unpack-'.$suffix;
        $old = $directory.'/.old-'.$suffix;
        $target = $directory.'/'.$platform->directory();
        $failure = null;

        try {
            Files::makeDirectory($temporary);
            new Unpack($this->commandLine)->to($archive, $platform, $temporary);

            // Where flock() does not exclude, another run may have moved in the same browser while
            // this one unpacked. A test may run it already, so it stays, and this tree goes.
            if ($this->isInstalled($pin, $platform)) {
                return;
            }

            $new = $temporary.'/'.$platform->directory();
            $stamp = $directory.'/'.self::STAMP;

            // Without the stamp, a failure from here on leaves a browser the next run unpacks again.
            if (is_file($stamp) && ! @unlink($stamp) && file_exists($stamp)) {
                throw new InstallerException(sprintf('The stamp in %s could not be removed.', $directory));
            }

            // Where flock() does not exclude, another run moves the same paths. It moves in only a
            // tree of the checked archive, so a path that it moved counts as moved.
            if (is_dir($target) && ! @rename($target, $old) && file_exists($target)) {
                throw new InstallerException(sprintf('%s could not be moved to %s.', $target, $old));
            }

            if (! @rename($new, $target) && ! is_dir($target)) {
                throw new InstallerException(sprintf('%s could not be moved to %s.', $new, $target));
            }
        } catch (Throwable $throwable) {
            $failure = $throwable;

            throw $throwable;
        } finally {
            $this->commandLine->remove([$temporary, $old], $failure);
        }
    }
}
