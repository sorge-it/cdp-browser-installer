<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * `install`: the SHA-256 differs from the pin, and nothing is unpacked. A retry helps only if the
 * run deleted an archive from the cache; the message names what happened to it. `pin`: the MD5 of
 * a download differs from the one the storage sent, and no pin is written.
 */
final class ChecksumException extends InstallerException
{
    /**
     * `EX_DATAERR` of sysexits.h: the bytes are not the pinned ones.
     */
    public const int EXIT_CODE = 65;
}
