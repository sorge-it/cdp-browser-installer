<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use RuntimeException;
use Throwable;

/**
 * The base class of each exception of the package. A retry can help after `DownloadException` and
 * `LockException`. `ChecksumException` means the bytes are not the expected ones. A human fixes any
 * other cause. The cause from safe-unzip, Symfony or PHP is the previous exception.
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
