<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Override;
use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Libc;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'path', description: 'Print the path of the pinned chrome-headless-shell or chromedriver for this platform')]
final class PathCommand extends Command
{
    /**
     * @param  Closure(): Installer  $installer
     * @param  Closure(): Platform  $platform
     */
    public function __construct(private readonly Closure $installer, private readonly Closure $platform)
    {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $names = array_map(static fn (Binary $binary): string => $binary->value, Binary::cases());
        // Optional for Symfony: without a name, the message of this command names both.
        $this->addArgument('binary', InputArgument::OPTIONAL, 'chrome-headless-shell or chromedriver', null, $names);
        $this->addOption('pin-file', null, InputOption::VALUE_REQUIRED, 'The pin file', Pin::FILE);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('binary');
        $binary = is_string($name) ? Binary::tryFrom($name) : null;

        if (! $binary instanceof Binary) {
            throw new InstallerException('Name the binary: `cdp-browser path chrome-headless-shell` or `cdp-browser path chromedriver`.');
        }

        $installer = ($this->installer)();
        $platform = ($this->platform)();
        $pinFile = Cli::text($input->getOption('pin-file'));

        if ($installer->ownBinaries() instanceof Binaries) {
            // The check of `install`: a path that `install` refuses is no path to print.
            $output->writeln($installer->binaries($pinFile, $platform)->path($binary));

            return self::SUCCESS;
        }

        // The message of `install`: on musl, "Run `cdp-browser install`" or "Write one with
        // `cdp-browser pin`" leads to its refusal.
        if ($installer->onMusl()) {
            throw new InstallerException(Libc::REFUSAL);
        }

        $pin = Pin::read($pinFile);

        if (! $installer->isInstalled($pin, $platform)) {
            throw new InstallerException(sprintf('chrome-headless-shell and chromedriver %s for %s are not installed. Run `cdp-browser install`.', $pin->version, $platform->value));
        }

        $output->writeln($installer->paths($pin, $platform)->path($binary));

        return self::SUCCESS;
    }
}
