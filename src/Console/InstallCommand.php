<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Override;
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
#[AsCommand(name: 'install', description: 'Install the pinned chrome-headless-shell for this platform')]
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

        // The system's browser is only checked: `install` then writes nothing.
        if ($installer->ownBrowser() === null) {
            Cli::refuseRoot($this->user, 'install', $input->getOption('allow-root') === true);
        }

        $platform = ($this->platform)();
        // A download of about 120 MiB takes a while; `pin` names each URL the same way.
        $binary = $installer->browser(Cli::text($input->getOption('pin-file')), $platform, static function (string $url) use ($output): void {
            $output->writeln('Download '.$url);
        });

        $output->writeln($installer->ownBrowser() === null
            ? sprintf('chrome-headless-shell for %s is at %s.', $platform->value, $binary)
            : sprintf("The system's browser is at %s (%s).", $binary, Installer::OWN_BROWSER));

        return self::SUCCESS;
    }
}
