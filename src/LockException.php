<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * Another install in the same directory held the lock past the lock timeout. Or, where `flock()`
 * does not lock, another install removed the checked archive of this install before this install
 * moved it back into the cache. A retry helps once the other install has ended.
 *
 * @api
 */
final class LockException extends InstallerException
{
    /**
     * `EX_TEMPFAIL` of sysexits.h: a script can run the command again.
     */
    public const int EXIT_CODE = 75;
}
