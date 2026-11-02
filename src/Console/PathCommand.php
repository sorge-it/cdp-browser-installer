<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Override;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Libc;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'path', description: 'Print the path of the pinned chrome-headless-shell for this platform')]
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
        $this->addOption('pin-file', null, InputOption::VALUE_REQUIRED, 'The pin file', Pin::FILE);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $installer = ($this->installer)();
        $platform = ($this->platform)();
        $pinFile = Cli::text($input->getOption('pin-file'));

        if ($installer->ownBrowser() !== null) {
            // The check of `install`: a path that `install` refuses is no path to print.
            $output->writeln($installer->browser($pinFile, $platform));

            return self::SUCCESS;
        }

        $pin = Pin::read($pinFile);

        // The message of `install`: on musl, "Run `cdp-browser install`" leads to its refusal.
        if ($installer->onMusl()) {
            throw new InstallerException(Libc::REFUSAL);
        }

        if (! $installer->isInstalled($pin, $platform)) {
            throw new InstallerException(sprintf('chrome-headless-shell %s for %s is not installed. Run `cdp-browser install`.', $pin->version, $platform->value));
        }

        $output->writeln($installer->binary($pin, $platform));

        return self::SUCCESS;
    }
}
