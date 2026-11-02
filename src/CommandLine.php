<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use Closure;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Starts every native tool of the installer from an argument list, never from a shell string.
 * The constructor is API: a caller gives each tool more variables, such as a `PATH`, and a
 * timeout.
 */
final readonly class CommandLine
{
    /**
     * The default timeout of a tool, in seconds.
     *
     * @internal
     */
    public const int SECONDS = 900;

    /**
     * The same messages on every system. `unzip` and `zipinfo` run through safe-unzip.
     */
    private const array FIXED = ['LC_ALL' => 'C'];

    /**
     * @param  array<string, string>  $environment  added to the environment of PHP for each process
     * @param  int  $timeout  the seconds of each process; `0` for no limit
     */
    public function __construct(private array $environment = [], private int $timeout = self::SECONDS)
    {
        // Symfony takes no negative timeout, and its exception would not name the installer.
        if ($timeout < 0) {
            throw new InstallerException(sprintf('The timeout of a tool is %d seconds. Use 0 for no limit, or a positive value.', $timeout));
        }
    }

    /**
     * @param  list<string>  $command  a tool name, or a path, and its arguments
     * @param  (Closure(string): void)|null  $output  gets the output as it arrives
     * @return string what the command printed
     *
     * @internal
     */
    public function run(array $command, ?Closure $output = null): string
    {
        $name = $command[0];
        $command[0] = $this->find($name);

        try {
            // Symfony adds the environment of PHP to the one given.
            $process = new Process($command, env: [...$this->environment, ...self::FIXED], timeout: $this->timeout);
            $process->run($output instanceof Closure ? static function (string $type, string $chunk) use ($output): void {
                $output($chunk);
            } : null);
        } catch (ExceptionInterface $exception) {
            // A timeout or a signal: the same kind of error as an exit code, for every caller.
            throw new InstallerException(sprintf('`%s` stopped: %s', $name, $exception->getMessage()), $exception);
        }

        if (! $process->isSuccessful()) {
            throw new InstallerException(sprintf('`%s` stopped with exit code %d: %s', $name, $process->getExitCode(), trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }

    /**
     * The same tools with another timeout.
     *
     * @internal
     */
    public function withTimeout(int $timeout): self
    {
        return new self($this->environment, $timeout);
    }

    /**
     * @return array<string, string> what each process gets on top of the environment of PHP
     *
     * @internal
     */
    public function environment(): array
    {
        return $this->environment;
    }

    /**
     * @return int the seconds of each process
     *
     * @internal
     */
    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * @internal
     */
    public function has(string $name): bool
    {
        return $this->path($name) !== null;
    }

    /**
     * Removes the paths with `rm -rf`, as a step of a `finally`. After a first error, a failed
     * removal does not replace it: the first error names the cause, and the next install removes
     * what this one left.
     *
     * @param  list<string>  $paths
     *
     * @internal
     */
    public function remove(array $paths, ?Throwable $failure): void
    {
        try {
            $this->run(['rm', '-rf', '--', ...$paths]);
        } catch (InstallerException $installerException) {
            if (! $failure instanceof Throwable) {
                throw $installerException;
            }
        }
    }

    /**
     * @return string the path of the tool
     *
     * @internal
     */
    public function find(string $name): string
    {
        return $this->path($name) ?? throw new InstallerException(sprintf('`%s` is not installed. On Debian, Ubuntu and Alpine, `cdp-browser deps` installs it.', $name));
    }

    /**
     * `proc_open` and Symfony's `ExecutableFinder` look a name up in the `PATH` of PHP, not in the
     * one the child gets. So the lookup is here, as in a shell: an empty entry is the working
     * directory, a relative one is taken from it.
     */
    private function path(string $name): ?string
    {
        if (str_contains($name, '/')) {
            return is_file($name) && is_executable($name) ? $name : null;
        }

        // Without a PATH, where `execvp()` looks, as safe-unzip does for `zipinfo` and `unzip`.
        foreach (explode(PATH_SEPARATOR, $this->environment['PATH'] ?? (getenv('PATH') ?: '/usr/bin:/bin')) as $directory) {
            $file = ($directory === '' ? '.' : $directory).'/'.$name;

            if (is_file($file) && is_executable($file)) {
                return $file;
            }
        }

        return null;
    }
}
