<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * `install`: the SHA-256 of an archive differs from the pin file; the archive is deleted, not
 * unpacked. A retry helps only for an archive from the cache; the message says which. `pin`: the
 * MD5 of a download differs from the one the storage sent; no pin file is written.
 *
 * @api
 */
final class ChecksumException extends InstallerException
{
    /**
     * `EX_DATAERR` of sysexits.h: the bytes are not the pinned ones.
     */
    public const int EXIT_CODE = 65;
}
