<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Override;
use SorgeIt\CdpBrowserInstaller\ChecksumException;
use SorgeIt\CdpBrowserInstaller\CommandLine;
use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\Environment;
use SorgeIt\CdpBrowserInstaller\Files;
use SorgeIt\CdpBrowserInstaller\InstallerException;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use SorgeIt\CdpBrowserInstaller\Unpack;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @internal
 */
#[AsCommand(name: 'pin', description: 'Download and check the archive of each platform, and write the version and each SHA-256 to the pin file')]
final class PinCommand extends Command
{
    /**
     * @param  Closure(): CommandLine  $commandLine  built when `pin` runs, so that a wrong `CDP_BROWSER_TIMEOUT` stops no other command
     */
    public function __construct(
        private readonly Closure $commandLine,
        private readonly int $user,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument('version', InputArgument::REQUIRED, 'A version of Chrome for Testing, such as 155.0.8059.39');
        $this->addOption('pin-file', null, InputOption::VALUE_REQUIRED, 'The pin file', Pin::FILE);
        $this->addOption('allow-root', null, InputOption::VALUE_NONE, Cli::ALLOW_ROOT);
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Cli::refuseRoot($this->user, 'pin', $input->getOption('allow-root') === true);
        $version = Cli::text($input->getArgument('version'));
        Pin::checkVersion($version);
        $pinFile = Cli::text($input->getOption('pin-file'));
        $this->checkPinFile($pinFile);
        $commandLine = ($this->commandLine)();

        // A directory of this run for each archive and its tree, in the temporary directory of the system.
        $directory = rtrim(Environment::temporaryDirectory(), '/').'/cdp-browser-pin-'.bin2hex(random_bytes(8));
        Files::makeDirectory($directory);
        $file = $directory.'/archive.zip';
        $headers = $directory.'/headers';
        $hashes = [];
        $failure = null;

        try {
            foreach (Platform::cases() as $platform) {
                $output->writeln(sprintf('Download %s', Download::url($version, $platform)));
                new Download($commandLine)->to($version, $platform, $file, $headers);
                $this->checkMd5($file, is_file($headers) ? (string) file_get_contents($headers) : '', $platform, $output);
                $hashes[$platform->value] = (string) hash_file('sha256', $file);

                // A response with status 200 can also be an error page. Only an archive that
                // `install` unpacks gets a pin. After a failure, the tree goes with the directory.
                new Unpack($commandLine)->to($file, $platform, $directory.'/tree');
                $commandLine->remove([$directory.'/tree'], null);
            }
        } catch (Throwable $throwable) {
            $failure = $throwable;

            throw $throwable;
        } finally {
            $commandLine->remove([$directory], $failure);
        }

        new Pin($version, $hashes)->write($pinFile);
        $output->writeln(sprintf('Wrote %s to %s. Review the change before you commit it.', $version, $pinFile));

        return self::SUCCESS;
    }

    /**
     * Before the four downloads, the checks that `Pin::write()` would fail after them: the pin
     * file is a file or missing, and its directory exists and takes a new file.
     */
    private function checkPinFile(string $pinFile): void
    {
        $target = Files::target($pinFile);
        $directory = dirname($target);

        if (file_exists($target) && ! is_file($target)) {
            throw new InstallerException(sprintf('The pin file %s is not a file. Remove it, or name another file with --pin-file.', $pinFile));
        }

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new InstallerException(sprintf('The pin file %s cannot be written: the directory %s does not exist or is not writable.', $pinFile, $directory));
        }
    }

    /**
     * The MD5 of the storage finds a broken download. It comes from the same server as the
     * archive, so it finds no changed source; without it, the SHA-256 alone checks `install`.
     */
    private function checkMd5(string $file, string $headers, Platform $platform, OutputInterface $output): void
    {
        $expected = Download::md5($headers);

        if ($expected === null) {
            $output->writeln(sprintf('The storage sent no MD5 for %s; pin compared none. The SHA-256 in the pin still checks install.', $platform->value));

            return;
        }

        $actual = (string) md5_file($file, true);

        if ($actual !== $expected) {
            throw new ChecksumException(sprintf('The MD5 of the download for %s is %s, the storage sent %s in x-goog-hash. The download is broken; no pin was written.', $platform->value, base64_encode($actual), base64_encode($expected)));
        }
    }
}
