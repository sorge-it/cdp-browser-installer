<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

/**
 * Downloads an archive of Chrome for Testing with `curl`, from one source only.
 *
 * @internal
 */
final readonly class Download
{
    public const string SOURCE = 'https://storage.googleapis.com/chrome-for-testing-public/';

    public function __construct(private CommandLine $commandLine) {}

    public static function url(string $version, Binary $binary, Platform $platform): string
    {
        return self::SOURCE.$version.'/'.$platform->value.'/'.$binary->directory($platform).'.zip';
    }

    /**
     * The MD5 that Google Cloud Storage sends in `x-goog-hash`, as 16 bytes, or `null` without
     * one. Only the last answer counts: a proxy puts the answer to its `CONNECT` first.
     *
     * @param  string  $headers  what `curl --dump-header` wrote
     */
    public static function md5(string $headers): ?string
    {
        $last = strrpos("\n".$headers, "\nHTTP/");
        // One line per hash, as Google sends them, or a list in one line. 22 characters and `==` are 16 bytes.
        $pattern = '~^[ \t]*x-goog-hash[ \t]*:(?:.*,)?[ \t]*md5=([A-Za-z0-9+/]{22}==)(?=[ \t\r]*(?:,|$))~mi';

        return preg_match($pattern, substr($headers, $last === false ? 0 : $last), $match) === 1 ? base64_decode($match[1]) : null;
    }

    /**
     * Writes the archive to the file, or throws and leaves no archive. The caller removes the
     * file of the headers.
     *
     * @param  string|null  $headers  a file for the headers of the answer, which `pin` reads; `install` needs none
     */
    public function to(string $version, Binary $binary, Platform $platform, string $file, ?string $headers = null): void
    {
        $url = self::url($version, $binary, $platform);
        // A missing curl is a cause of the host, not of the download.
        $this->commandLine->find('curl');

        try {
            $status = $this->commandLine->run([
                // First: curl reads no `.curlrc`, so no option comes from outside this list.
                'curl', '-q',
                '--silent', '--show-error',
                // HTTPS with TLS 1.2 or later. TLS verification is the default of curl; no option turns it off.
                '--proto', '=https', '--tlsv1.2',
                // No `--location`: curl follows no redirect, and a 3xx is no 200.
                '--connect-timeout', '30',
                // Below 1 KiB/s for a minute, or above 500 MiB, the download stops.
                '--speed-limit', '1024', '--speed-time', '60',
                '--max-filesize', '524288000',
                '--output', $file,
                ...($headers === null ? [] : ['--dump-header', $headers]),
                '--write-out', '%{http_code}',
                $url,
            ]);
        } catch (InstallerException $installerException) {
            $this->remove($file);

            // The cause of the stop, such as the timeout of Symfony, not a second exception of the installer.
            throw new DownloadException(sprintf('The download of %s stopped. %s', $url, $installerException->getMessage()), $installerException->getPrevious());
        }

        if ($status === '200') {
            return;
        }

        $this->remove($file);

        // A wrong version, such as a typo in `pin`: a retry does not help.
        if ($status === '404') {
            throw new InstallerException(sprintf('%s answered with status 404: version %s does not exist for %s. Check the version on https://googlechromelabs.github.io/chrome-for-testing/.', $url, $version, $platform->value));
        }

        throw new DownloadException(sprintf('%s answered with status %s.%s', $url, $status, str_starts_with($status, '3') ? ' The installer follows no redirect.' : ''));
    }

    private function remove(string $file): void
    {
        // A failed removal does not replace the error of the download.
        if (is_file($file)) {
            Files::quietly(static fn (): bool => unlink($file));
        }
    }
}
