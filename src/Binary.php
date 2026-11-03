<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * The two binaries of one version of Chrome for Testing that the installer pins and installs as
 * a pair: the browser and the WebDriver server of the same build. The value is the name that
 * Chrome for Testing uses for the archive and for the binary in it.
 */
enum Binary: string
{
    case ChromeHeadlessShell = 'chrome-headless-shell';
    case Chromedriver = 'chromedriver';

    /**
     * The variable that names the system's binary, such as one of Alpine.
     */
    public function variable(): string
    {
        return match ($this) {
            self::ChromeHeadlessShell => Installer::OWN_CHROME,
            self::Chromedriver => Installer::OWN_CHROMEDRIVER,
        };
    }

    /**
     * Where Alpine's package puts the binary: Chrome for Testing does not start on musl.
     */
    public function alpinePath(): string
    {
        return match ($this) {
            self::ChromeHeadlessShell => '/usr/bin/chromium',
            self::Chromedriver => '/usr/bin/chromedriver',
        };
    }

    /**
     * The top directory inside the archive, and beside it in the cache.
     */
    public function directory(Platform $platform): string
    {
        return $this->value.'-'.$platform->value;
    }

    /**
     * The path of the binary inside the archive.
     */
    public function path(Platform $platform): string
    {
        return $this->directory($platform).'/'.$this->value;
    }
}
