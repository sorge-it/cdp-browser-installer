<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * `install`: the SHA-256 of an archive differs from the pin; that archive is deleted and was not
 * unpacked. A retry helps only if the run deleted an archive from the cache. `pin`: the MD5 of a
 * download differs from the one the storage sent, and no pin is written.
 */
final class ChecksumException extends InstallerException
{
    /**
     * `EX_DATAERR` of sysexits.h: the bytes are not the pinned ones.
     */
    public const int EXIT_CODE = 65;
}
