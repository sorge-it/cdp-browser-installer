<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Console;

use Closure;
use Override;
use SorgeIt\CdpBrowserInstaller\Binary;
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
#[AsCommand(name: 'pin', description: 'Download and check the archives of chrome-headless-shell and chromedriver for each platform, and write the version and each SHA-256 to the pin file')]
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
            foreach (Binary::cases() as $binary) {
                foreach (Platform::cases() as $platform) {
                    $output->writeln(sprintf('Download %s', Download::url($version, $binary, $platform)));
                    new Download($commandLine)->to($version, $binary, $platform, $file, $headers);
                    $this->checkMd5($file, is_file($headers) ? $this->read($headers, static fn (): string|false => file_get_contents($headers)) : '', $binary, $platform, $output);
                    $hashes[$binary->value][$platform->value] = $this->read($file, static fn (): string|false => hash_file('sha256', $file));

                    // A response with status 200 can also be an error page. Only an archive that
                    // `install` unpacks gets a pin. After a failure, the tree goes with the directory.
                    new Unpack($commandLine)->to($file, $binary, $platform, $directory.'/tree');
                    $commandLine->remove([$directory.'/tree'], null);
                }
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
     * A file of the download that cannot be read stops `pin` as a cause of the file system: no
     * checksum was compared, so it is no `ChecksumException`.
     *
     * @param  Closure(): (string|false)  $read
     */
    private function read(string $file, Closure $read): string
    {
        $content = Files::quietly($read);

        if ($content === false) {
            throw new InstallerException(sprintf('The file %s of the download could not be read; no pin was written.', $file));
        }

        return $content;
    }

    /**
     * Before the downloads, the checks that `Pin::write()` would fail after them: the pin
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
    private function checkMd5(string $file, string $headers, Binary $binary, Platform $platform, OutputInterface $output): void
    {
        $expected = Download::md5($headers);

        if ($expected === null) {
            $output->writeln(sprintf('The storage sent no MD5 for %s on %s; pin compared none. The SHA-256 in the pin still checks install.', $binary->value, $platform->value));

            return;
        }

        $actual = $this->read($file, static fn (): string|false => md5_file($file, true));

        if ($actual !== $expected) {
            throw new ChecksumException(sprintf('The MD5 of the download of %s for %s is %s, the storage sent %s in x-goog-hash. The download is broken; no pin was written.', $binary->value, $platform->value, base64_encode($actual), base64_encode($expected)));
        }
    }
}
