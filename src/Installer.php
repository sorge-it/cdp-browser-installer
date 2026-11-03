<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use Closure;
use LogicException;
use RuntimeException;
use SplFileObject;
use Throwable;

/**
 * Puts the pair of chrome-headless-shell and chromedriver under `<cache>/<version>/<platform>/`,
 * or uses a pair the system brings. Each archive stays beside its tree. Its SHA-256 is checked
 * before it is unpacked.
 */
final readonly class Installer
{
    /**
     * The system's browser, such as Alpine's chromium. Only together with `OWN_CHROMEDRIVER`.
     */
    public const string OWN_CHROME = 'CDP_BROWSER_CHROME';

    /**
     * The system's chromedriver. Only together with `OWN_CHROME`.
     */
    public const string OWN_CHROMEDRIVER = 'CDP_BROWSER_CHROMEDRIVER';

    /**
     * The seconds of each tool, such as the whole download by curl.
     */
    public const string TIMEOUT = 'CDP_BROWSER_TIMEOUT';

    /**
     * The seconds an install waits for another one in the same directory.
     */
    public const string LOCK_TIMEOUT = 'CDP_BROWSER_LOCK_TIMEOUT';

    /**
     * A file beside the pair with one line for each binary: the SHA-256 of the archive it was
     * unpacked from.
     */
    private const string STAMP = '.sha256-pair';

    /**
     * The variable of release 1.x for the system's browser. Its value is never used.
     */
    private const string RELEASE_1_BROWSER = 'CDP_BROWSER_BINARY';

    /**
     * The seconds after which a leftover counts as one of a stopped run; curl stops below 1 KiB/s
     * for 60 s. Without a working `flock()`, a second run can remove a file of a run whose step
     * takes longer; that run then stops with exit code 1.
     */
    private const int STOPPED = 120;

    private const int LOCK_SECONDS = 600;

    private ?string $cache;

    private bool $musl;

    /**
     * @param  string|null  $cache  an absolute directory, not the root, without a character that unzip reads as a pattern; `null` for an installer that only checks the system's pair
     * @param  Binaries|null  $own  the system's pair, such as Alpine's chromium and chromedriver; then nothing is downloaded
     * @param  bool|null  $musl  whether Linux runs on musl, where Chrome for Testing does not start; `null` detects it
     * @param  int  $lockTimeout  how many seconds an install waits for another one in the same directory; `0` not to wait
     */
    public function __construct(
        private CommandLine $commandLine,
        ?string $cache,
        private ?Binaries $own = null,
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
     * The installer as the environment configures it: `CDP_BROWSER_CHROME`,
     * `CDP_BROWSER_CHROMEDRIVER`, `CDP_BROWSER_PATH`, `XDG_CACHE_HOME`, `HOME`,
     * `CDP_BROWSER_TIMEOUT` and `CDP_BROWSER_LOCK_TIMEOUT`. A test tool starts here.
     *
     * @param  array<string, string>  $environment
     * @param  CommandLine  $commandLine  its timeout is the default of `CDP_BROWSER_TIMEOUT`
     */
    public static function fromEnvironment(array $environment, CommandLine $commandLine = new CommandLine, ?bool $musl = null): self
    {
        // Without this stop, a pipeline that sets it gets the pinned pair and no message.
        if (($environment[self::RELEASE_1_BROWSER] ?? '') !== '') {
            throw new InstallerException(sprintf("%s is set, but release 2.x does not read it. Set %s and %s for the system's pair, such as %s and %s, or unset %s for the pinned pair.", self::RELEASE_1_BROWSER, self::OWN_CHROME, self::OWN_CHROMEDRIVER, Binary::ChromeHeadlessShell->alpinePath(), Binary::Chromedriver->alpinePath(), self::RELEASE_1_BROWSER));
        }

        $browser = $environment[self::OWN_CHROME] ?? '';
        $chromedriver = $environment[self::OWN_CHROMEDRIVER] ?? '';

        // A browser of the system and a pinned chromedriver, or the reverse, can differ in version.
        if (($browser === '') !== ($chromedriver === '')) {
            [$set, $missing] = $browser === '' ? [self::OWN_CHROMEDRIVER, self::OWN_CHROME] : [self::OWN_CHROME, self::OWN_CHROMEDRIVER];

            throw new InstallerException(sprintf('%s is set, but %s is not. Set both for the pair of the system, such as %s and %s, or neither for the pinned pair.', $set, $missing, Binary::ChromeHeadlessShell->alpinePath(), Binary::Chromedriver->alpinePath()));
        }

        $commandLine = Environment::applyTimeout($environment, $commandLine);

        // The system's pair needs no cache directory, and so no HOME, and takes no lock.
        if ($browser !== '') {
            return new self($commandLine, null, new Binaries($browser, $chromedriver), $musl);
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

    public function ownBinaries(): ?Binaries
    {
        return $this->own;
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
     * The pair to start: the system's pair, checked, or the pinned one, installed if missing. One
     * call for a test tool.
     *
     * @param  (Closure(string): void)|null  $onDownload  gets the URL before each download
     */
    public function binaries(string $pinFile, Platform $platform, ?Closure $onDownload = null): Binaries
    {
        if ($this->own instanceof Binaries) {
            return $this->checkOwn($this->own);
        }

        // Before the pin file: on musl, a new pin file leads only to the refusal of `install()`.
        if ($this->musl) {
            throw new InstallerException(Libc::REFUSAL);
        }

        return $this->install(Pin::read($pinFile), $platform, $onDownload);
    }

    /**
     * Where the pinned pair is, or goes, in the cache.
     */
    public function paths(Pin $pin, Platform $platform): Binaries
    {
        $directory = $this->directory($pin, $platform);

        return new Binaries(
            $directory.'/'.Binary::ChromeHeadlessShell->path($platform),
            $directory.'/'.Binary::Chromedriver->path($platform),
        );
    }

    /**
     * Whether both binaries are there, and the stamp names the archives of this pin.
     */
    public function isInstalled(Pin $pin, Platform $platform): bool
    {
        $paths = $this->paths($pin, $platform);
        $stamp = $this->directory($pin, $platform).'/'.self::STAMP;

        return is_file($paths->path(Binary::ChromeHeadlessShell))
            && is_file($paths->path(Binary::Chromedriver))
            && is_file($stamp)
            && Files::quietly(static fn (): string|false => file_get_contents($stamp)) === $this->stamp($pin, $platform);
    }

    /**
     * Unpacks both binaries when the pair is not installed, one after the other. Each archive, a
     * new download or one in the cache, is checked once in a file of this run and unpacked from
     * that file. An installed pair needs no lock, and so no write access to the cache.
     *
     * @param  (Closure(string): void)|null  $onDownload  gets the URL before each download
     */
    public function install(Pin $pin, Platform $platform, ?Closure $onDownload = null): Binaries
    {
        if ($this->musl) {
            throw new InstallerException(Libc::REFUSAL);
        }

        if ($this->isInstalled($pin, $platform)) {
            return $this->paths($pin, $platform);
        }

        $directory = $this->directory($pin, $platform);
        Files::makeDirectory($directory);
        $lock = $this->lock($directory);

        try {
            $this->removeLeftovers($directory, $platform);

            if (! $this->isInstalled($pin, $platform)) {
                foreach (Binary::cases() as $binary) {
                    $this->installArchive($binary, $directory, $pin, $platform, $onDownload);
                }

                if (! Files::write($directory.'/'.self::STAMP, $this->stamp($pin, $platform))) {
                    throw new InstallerException(sprintf('The stamp in %s could not be written.', $directory));
                }
            }
        } finally {
            $lock->flock(LOCK_UN);
        }

        return $this->paths($pin, $platform);
    }

    /**
     * One line for each binary, with the SHA-256 of its archive.
     */
    private function stamp(Pin $pin, Platform $platform): string
    {
        $lines = '';

        foreach (Binary::cases() as $binary) {
            $lines .= $binary->value.' '.$pin->hash($binary, $platform)."\n";
        }

        return $lines;
    }

    /**
     * Removes what a run leaves when it ends before its `finally`, as after SIGKILL: a download, a
     * temporary or an old tree, a new stamp. An old tree of a run that still runs goes either way.
     */
    private function removeLeftovers(string $directory, Platform $platform): void
    {
        $archives = array_map(static fn (Binary $binary): string => preg_quote($binary->directory($platform).'.zip', '/').'\.part-', Binary::cases());
        $pattern = '/\A(?:'.implode('|', $archives).'|\.unpack-|\.old-|'.preg_quote(self::STAMP, '/').'\.)[0-9a-f]{8}\z/';

        foreach (Files::quietly(static fn (): array|false => scandir($directory)) ?: [] as $entry) {
            $path = $directory.'/'.$entry;

            // Another run without a working lock may remove the same path first.
            if (preg_match($pattern, $entry) !== 1 || (int) Files::quietly(static fn (): int|false => filemtime($path)) >= time() - self::STOPPED) {
                continue;
            }

            if (is_dir($path) && ! is_link($path)) {
                $this->commandLine->run(['rm', '-rf', '--', $path]);
            } elseif (! Files::quietly(static fn (): bool => unlink($path)) && file_exists($path)) {
                throw new InstallerException(sprintf('The leftover %s could not be removed.', $path));
            }
        }
    }

    /**
     * Without a working `flock()`, a second run can remove a file of this run by its age.
     */
    private function move(string $from, string $to): void
    {
        if (! Files::quietly(static fn (): bool => rename($from, $to))) {
            throw new InstallerException(sprintf('%s could not be moved to %s.%s', $from, $to, file_exists($from) ? '' : ' Another install removed it, as where flock() does not exclude it. Run `cdp-browser install` again.'));
        }
    }

    /**
     * Checks and unpacks the archive of the binary in a file of this run, then puts it back for
     * the cache. Without a working `flock()`, a second run can put another archive at the path of
     * the cache meanwhile; this run still unpacks what it checked.
     *
     * @param  (Closure(string): void)|null  $onDownload
     */
    private function installArchive(Binary $binary, string $directory, Pin $pin, Platform $platform, ?Closure $onDownload): void
    {
        $archive = $directory.'/'.$binary->directory($platform).'.zip';
        $own = $archive.'.part-'.bin2hex(random_bytes(4));

        try {
            // A second run without a working lock removes a `.part-*` file by its age, and a rename
            // keeps the old age of a file that a cache restored. So the time is set before the rename.
            if (is_file($archive) && ! Files::quietly(static fn (): bool => touch($archive))) {
                throw new InstallerException(sprintf('The archive %s of the cache could not get a new modification time. Remove it; the next `cdp-browser install` downloads it again.', $archive));
            }

            // Without an archive in the cache, or when another run took it first, this run downloads.
            if (! is_file($archive) || ! Files::quietly(static fn (): bool => rename($archive, $own))) {
                if (file_exists($archive)) {
                    throw new InstallerException(sprintf('%s could not be moved to %s.', $archive, $own));
                }

                if ($onDownload instanceof Closure) {
                    $onDownload(Download::url($pin->version, $binary, $platform));
                }

                new Download($this->commandLine)->to($pin->version, $binary, $platform, $own);
            }

            $this->verify($own, $archive, $pin->hash($binary, $platform));

            try {
                $this->unpack($own, $binary, $directory, $pin, $platform);
            } catch (Throwable $throwable) {
                // The checked archive stays in the cache, also when its unpack fails.
                Files::quietly(static fn (): bool => rename($own, $archive));

                throw $throwable;
            }

            $this->move($own, $archive);
        } finally {
            // A failed removal does not replace the first error; the next run removes the file.
            if (is_file($own)) {
                Files::quietly(static fn (): bool => unlink($own));
            }
        }
    }

    private function directory(Pin $pin, Platform $platform): string
    {
        $cache = $this->cache ?? throw new InstallerException("This installer has no cache directory: it was made for the system's pair.");

        return $cache.'/'.$pin->version.'/'.$platform->value;
    }

    /**
     * Each binary of the system's pair must be an absolute path to a program that answers
     * `--version`.
     */
    private function checkOwn(Binaries $own): Binaries
    {
        foreach (Binary::cases() as $binary) {
            $path = $own->path($binary);
            $variable = $binary->variable();

            if (! str_starts_with($path, '/')) {
                throw new InstallerException(sprintf('%s must be an absolute path, not `%s`.', $variable, $path));
            }

            // Before `run()`: its message for a missing tool names `deps`, which installs nothing at this path.
            if (! $this->commandLine->has($path)) {
                throw new InstallerException(sprintf('%1$s `%2$s` is not an executable file. Set %1$s to the path of an existing binary, such as %3$s.', $variable, $path, $binary->alpinePath()));
            }

            try {
                $this->commandLine->run([$path, '--version']);
            } catch (InstallerException $installerException) {
                throw new InstallerException(sprintf('%s `%s` does not answer --version. %s', $variable, $path, $installerException->getMessage()), $installerException->getPrevious());
            }
        }

        return $own;
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
        $handle = Files::quietly(static fn (): mixed => fopen($file, 'rb'));

        if ($handle === false) {
            throw new InstallerException(sprintf('The archive %s could not be read.', $archive));
        }

        $context = hash_init('sha256');

        try {
            // Blocks of 1 MiB. A failed read ends with an error, not with the hash of part of the file.
            while (($block = Files::quietly(static fn (): string|false => fread($handle, 1_048_576))) !== '') {
                if ($block === false) {
                    throw new InstallerException(sprintf('The archive %s could not be read.', $archive));
                }

                hash_update($context, $block);
            }
        } finally {
            fclose($handle);
        }

        $actual = hash_final($context);

        if ($actual !== $expected) {
            throw new ChecksumException(sprintf('The SHA-256 of %s is %s, the pin expects %s. The archive is deleted and was not unpacked.', $archive, $actual, $expected));
        }
    }

    /**
     * Unpacks the checked archive into a new directory beside the target. The old tree moves
     * aside, the new one moves in, then the old one is removed. A binary that still runs from
     * the old tree keeps only the files it has open.
     */
    private function unpack(string $archive, Binary $binary, string $directory, Pin $pin, Platform $platform): void
    {
        $suffix = bin2hex(random_bytes(4));
        $temporary = $directory.'/.unpack-'.$suffix;
        $old = $directory.'/.old-'.$suffix;
        $target = $directory.'/'.$binary->directory($platform);
        $failure = null;

        try {
            Files::makeDirectory($temporary);
            new Unpack($this->commandLine)->to($archive, $binary, $platform, $temporary);

            // Where flock() does not exclude, another run may have moved in the same pair while
            // this one unpacked. A test may run it already, so it stays, and this tree goes.
            if ($this->isInstalled($pin, $platform)) {
                return;
            }

            $new = $temporary.'/'.$binary->directory($platform);
            $stamp = $directory.'/'.self::STAMP;

            // Without the stamp, a failure from here on leaves a pair the next run unpacks again.
            if (is_file($stamp) && ! Files::quietly(static fn (): bool => unlink($stamp)) && file_exists($stamp)) {
                throw new InstallerException(sprintf('The stamp in %s could not be removed.', $directory));
            }

            // Where flock() does not exclude, another run moves the same paths. It moves in only a
            // tree of the checked archive, so a path that it moved counts as moved.
            if (is_dir($target) && ! Files::quietly(static fn (): bool => rename($target, $old)) && file_exists($target)) {
                throw new InstallerException(sprintf('%s could not be moved to %s.', $target, $old));
            }

            if (! Files::quietly(static fn (): bool => rename($new, $target)) && ! is_dir($target)) {
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
