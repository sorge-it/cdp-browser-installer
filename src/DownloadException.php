<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * `curl` stops, or the source answers with a status other than 200 or 404. A retry can help, as
 * after a network error or a 5xx status; the message names the status or the exit code. A 404
 * names a version that does not exist, an `InstallerException`.
 */
final class DownloadException extends InstallerException
{
    /**
     * `EX_TEMPFAIL` of sysexits.h: a script can run the command again.
     */
    public const int EXIT_CODE = 75;
}
