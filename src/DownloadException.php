<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * `curl` fails with a signal, at the tool timeout, or with an exit code other than 23, 60, 63 or 77.
 * Or the source responds with a status other than 200 or 404. A retry can help, such as after a
 * network error or a 5xx status; the message names the status or the exit code.
 *
 * @api
 */
final class DownloadException extends InstallerException
{
    /**
     * `EX_TEMPFAIL` of sysexits.h: a script can run the command again.
     */
    public const int EXIT_CODE = 75;
}
