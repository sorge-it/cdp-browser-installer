<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Override;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\SystemLibraries;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal
 */
#[AsCommand(name: 'deps', description: 'Install curl, unzip and the libraries of chrome-headless-shell through apt, or the chromium of Alpine through apk; run it as root')]
final class DepsCommand extends Command
{
    /**
     * @param  Closure(): Platform  $platform
     */
    public function __construct(
        private readonly SystemLibraries $libraries,
        private readonly Closure $platform,
        private readonly int $user,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (in_array(($this->platform)(), [Platform::MacX64, Platform::MacArm64], true)) {
            $output->writeln('macOS brings curl, unzip and every library chrome-headless-shell needs. Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->user !== 0) {
            throw new InstallerException('`deps` installs packages through apt or apk and needs root. Run it with sudo.');
        }

        $next = $this->libraries->install($output->write(...));
        $output->writeln(trim('The packages are installed. '.$next));

        return self::SUCCESS;
    }
}
