<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use SorgeIt\SafeUnzip\Exception\UnzipException;
use SorgeIt\SafeUnzip\ZipArchive;

/**
 * Unpacks an archive of Chrome for Testing through safe-unzip. `install` and `pin` take an
 * archive only if it holds the binary of its platform.
 *
 * @internal
 */
final readonly class Unpack
{
    public function __construct(private CommandLine $commandLine) {}

    /**
     * Writes the tree of the archive into the directory, or throws.
     */
    public function to(string $archive, Platform $platform, string $directory): void
    {
        try {
            $zip = new ZipArchive(environment: $this->commandLine->environment(), timeout: $this->commandLine->timeout());
            // safe-unzip always checks: `CHECKCONS` changes nothing, and `close()` only forgets the listing.
            $zip->open($archive, ZipArchive::RDONLY);
            $zip->extractTo($directory);
        } catch (UnzipException $unzipException) {
            // No `ChecksumException`: `install` checked the SHA-256 before, `pin` takes it from these bytes.
            throw new InstallerException($unzipException->getMessage(), $unzipException);
        }

        if (! is_file($directory.'/'.$platform->binary())) {
            throw new InstallerException(sprintf('The archive %s does not contain %s.', $archive, $platform->binary()));
        }
    }
}
