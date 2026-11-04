<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Override;
use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
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
#[AsCommand(name: 'install', description: 'Install the pinned chrome-headless-shell and chromedriver for this platform')]
final class InstallCommand extends Command
{
    /**
     * @param  Closure(): Installer  $installer
     * @param  Closure(): Platform  $platform
     */
    public function __construct(
        private readonly Closure $installer,
        private readonly Closure $platform,
        private readonly int $user,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addOption('pin-file', null, InputOption::VALUE_REQUIRED, 'The pin file', Pin::FILE);
        $this->addOption('allow-root', null, InputOption::VALUE_NONE, Cli::ALLOW_ROOT);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $installer = ($this->installer)();

        // The system's pair is only checked: `install` then writes nothing.
        if (! $installer->ownBinaries() instanceof Binaries) {
            Cli::refuseRoot($this->user, 'install', $input->getOption('allow-root') === true);
        }

        $platform = ($this->platform)();
        // A download of an archive takes a while; `pin` names each URL the same way.
        $binaries = $installer->binaries(Cli::text($input->getOption('pin-file')), $platform, static function (string $url) use ($output): void {
            $output->writeln('Download '.$url);
        });

        foreach (Binary::cases() as $binary) {
            $output->writeln($installer->ownBinaries() instanceof Binaries
                ? sprintf("The system's %s is at %s (%s).", $binary->value, $binaries->path($binary), $binary->variable())
                : sprintf('%s for %s is at %s.', $binary->value, $platform->value, $binaries->path($binary)));
        }

        return self::SUCCESS;
    }
}
