<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use Closure;

/**
 * The packages `install` and the pair need, from the signed sources of the system. Fixed lists,
 * so that the command never installs a name it read from a download.
 *
 * @internal
 */
final readonly class SystemLibraries
{
    /**
     * Debian 13 and Ubuntu 24.04 and later: the names after the 64-bit time transition. On an older
     * release apt stops at the first unknown name.
     */
    public const array APT = [
        // What `install` runs: a minimal image has neither curl nor unzip.
        'ca-certificates',
        'curl',
        'unzip',
        // What chrome-headless-shell links against; chromedriver needs no other library.
        'fonts-liberation',
        'libasound2t64',
        'libatk-bridge2.0-0t64',
        'libatk1.0-0t64',
        'libatspi2.0-0t64',
        'libcairo2',
        'libcups2t64',
        'libdbus-1-3',
        'libdrm2',
        'libgbm1',
        'libglib2.0-0t64',
        'libnspr4',
        'libnss3',
        'libpango-1.0-0',
        'libx11-6',
        'libxcb1',
        'libxcomposite1',
        'libxdamage1',
        'libxext6',
        'libxfixes3',
        'libxkbcommon0',
        'libxrandr2',
    ];

    /**
     * Alpine: Chrome for Testing does not run on musl, so the pair comes from Alpine itself, as
     * Puppeteer and Symfony Panther document it. Both packages come from one build of chromium.
     */
    public const array APK = ['chromium', 'chromium-chromedriver', 'nss', 'freetype', 'harfbuzz', 'ttf-freefont'];

    /**
     * @param  CommandLine  $commandLine  finds apk or apt-get in its `PATH`
     */
    public function __construct(private CommandLine $commandLine) {}

    /**
     * @param  Closure(string): void  $output  gets the output of the package manager as it arrives
     * @return string what the user does next, if anything
     */
    public function install(Closure $output): string
    {
        // No timeout: stopped on a timeout, apt-get leaves dpkg running or a package half
        // configured, and the next run waits for the lock of dpkg. apt-get asks no question.
        $commandLine = new CommandLine(['DEBIAN_FRONTEND' => 'noninteractive', ...$this->commandLine->environment()], timeout: 0);

        if ($commandLine->has('apk')) {
            $commandLine->run(['apk', 'add', '--no-cache', ...self::APK], $output);

            return sprintf('Set %s to %s and %s to %s; `install` then uses this pair.', Installer::OWN_CHROME, Binary::ChromeHeadlessShell->alpinePath(), Installer::OWN_CHROMEDRIVER, Binary::Chromedriver->alpinePath());
        }

        if ($commandLine->has('apt-get')) {
            $commandLine->run(['apt-get', 'update'], $output);
            $commandLine->run(['apt-get', 'install', '--yes', '--no-install-recommends', ...self::APT], $output);

            return '';
        }

        throw new InstallerException('`deps` installs through apt or apk, and this system has neither. Install the libraries of `ldd chrome-headless-shell chromedriver` yourself.');
    }
}
