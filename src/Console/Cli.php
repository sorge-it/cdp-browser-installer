<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Composer\InstalledVersions;
use Override;
use SorgeIt\CdpBrowserInstaller\CommandLine;
use SorgeIt\CdpBrowserInstaller\Environment;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\SystemLibraries;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The application behind `vendor/bin/cdp-browser`. Each argument has the value of the running
 * system as its default; a test gives its own.
 *
 * @internal
 */
final class Cli
{
    public const string PACKAGE = 'sorge-it/cdp-browser-installer';

    /**
     * The help of `--allow-root` of `install` and `pin`.
     */
    public const string ALLOW_ROOT = 'Run as root, as in a container where no other user follows. A later run as another user cannot replace what root writes';

    /**
     * A command builds the installer and finds the platform only when it runs: `list`, `pin` and
     * `deps` work without a cache directory, and `pin` on any platform.
     *
     * @param  array<string, string>  $environment
     * @param  int  $user  the effective user id
     * @param  Closure(): Platform  $platform  as a rule `Platform::current(...)`
     * @param  bool|null  $musl  whether Linux runs on musl; `null` detects it
     */
    public static function application(
        array $environment,
        int $user,
        Closure $platform,
        CommandLine $commandLine = new CommandLine,
        ?bool $musl = null,
    ): Application {
        $installer = static fn (): Installer => Installer::fromEnvironment($environment, $commandLine, $musl);
        // `pin` downloads with the timeout of `install`.
        $download = static fn (): CommandLine => Environment::applyTimeout($environment, $commandLine);

        $application = new class('cdp-browser', self::version(self::PACKAGE)) extends Application
        {
            /**
             * The message of an `InstallerException` names the cause and the next step: it stands
             * alone, as written. `-v` shows the error as Symfony does, with the trace.
             */
            #[Override]
            public function renderThrowable(Throwable $e, OutputInterface $output): void
            {
                if ($e instanceof InstallerException && ! $output->isVerbose()) {
                    $output->writeln($e->getMessage(), OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);

                    return;
                }

                parent::renderThrowable($e, $output);
            }
        };
        $application->addCommands([
            new PinCommand($download, $user),
            new InstallCommand($installer, $platform, $user),
            new PathCommand($installer, $platform),
            new DepsCommand(new SystemLibraries($commandLine), $platform, $user),
        ]);

        return $application;
    }

    /**
     * The version that Composer installed. Without Composer, such as a copy of `src`, there is none.
     */
    public static function version(string $package): string
    {
        // A package that another one replaces or provides has no version of its own.
        $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package) ? InstalledVersions::getPrettyVersion($package) : null;

        return $version ?? sprintf('of no known version: Composer does not list %s', $package);
    }

    /**
     * Only `deps` needs root. A browser or a pin file owned by root breaks the next run as a
     * normal user; in a container where no other user follows, `--allow-root` takes that risk.
     */
    public static function refuseRoot(int $user, string $command, bool $allowed): void
    {
        if ($user === 0 && ! $allowed) {
            throw new InstallerException(sprintf(
                '`%s` does not run as root: it writes files that root owns, and a later run as another user cannot replace them. Only `deps` needs root. Where no other user runs it, as in a container, add --allow-root.',
                $command,
            ));
        }
    }

    /**
     * The value of an argument or an option that Symfony gives as `mixed`.
     */
    public static function text(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InstallerException('An argument or an option has no text value.');
        }

        return $value;
    }
}
