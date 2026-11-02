<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use RuntimeException;
use Throwable;

/**
 * Each error of the package; the message names the cause and the next step. Two subclasses name
 * the archive: `DownloadException` and `ChecksumException`. For each other cause, a human fixes
 * it. An exception of safe-unzip, Symfony or PHP behind it is its previous exception.
 *
 * @api
 */
class InstallerException extends RuntimeException
{
    /**
     * The code of the exception, and so the exit code of `bin/cdp-browser`.
     */
    public const int EXIT_CODE = 1;

    /**
     * Only the package throws it.
     *
     * @internal
     */
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, static::EXIT_CODE, $previous);
    }
}
