<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use JsonException;

/**
 * One version of Chrome for Testing and the SHA-256 of each archive of the pair. Chrome for
 * Testing publishes no SHA-256 and no signature; its storage sends an MD5 and a CRC32C. So `pin`
 * compares each download with the MD5 and computes its SHA-256; a human reviews the pin file.
 *
 * @api
 */
final readonly class Pin
{
    /**
     * The pin file in the working directory, where `pin`, `install` and `path` look without
     * `--pin-file`.
     */
    public const string FILE = '.chrome-version';

    /**
     * `\z`, not `$`: `$` also matches before a line break at the end.
     */
    private const string VERSION = '/\A\d+\.\d+\.\d+\.\d+\z/';

    private const string SHA256 = '/\A[0-9a-f]{64}\z/';

    /**
     * @param  array<string, array<string, string>>  $hashes  the SHA-256 for each value of `Binary` and each value of `Platform`
     */
    public function __construct(public string $version, private array $hashes)
    {
        self::checkVersion($version);

        foreach (Binary::cases() as $binary) {
            foreach (Platform::cases() as $platform) {
                $hash = $hashes[$binary->value][$platform->value] ?? '';

                if (preg_match(self::SHA256, $hash) !== 1) {
                    throw new InstallerException(sprintf('The pin file for %s has no valid SHA-256 of %s for %s.', $version, $binary->value, $platform->value));
                }
            }
        }
    }

    public static function checkVersion(string $version): void
    {
        if (preg_match(self::VERSION, $version) !== 1) {
            throw new InstallerException(sprintf('`%s` is not a Chrome for Testing version. Use four numbers separated by dots, such as the Stable version on https://googlechromelabs.github.io/chrome-for-testing/.', $version));
        }
    }

    /**
     * The option that a command in a message needs to reach this pin file, or nothing for the
     * default one. A path beyond plain characters is quoted for the shell.
     *
     * @internal
     */
    public static function option(string $file): string
    {
        if ($file === self::FILE) {
            return '';
        }

        return ' --pin-file '.(preg_match('~\A[\w./-]+\z~', $file) === 1 ? $file : escapeshellarg($file));
    }

    public static function read(string $file): self
    {
        if (! is_file($file)) {
            // A relative path counts from the working directory, which a test runner may change.
            throw new InstallerException(sprintf('There is no pin file at %s, seen from the working directory %s. Write one with `vendor/bin/cdp-browser pin VERSION%s`, with VERSION from https://googlechromelabs.github.io/chrome-for-testing/.', $file, (string) getcwd(), self::option($file)));
        }

        $content = Files::quietly(static fn (): string|false => file_get_contents($file));

        if ($content === false) {
            throw new InstallerException(sprintf('The pin file %s could not be read. Check that this user can read it.', $file));
        }

        try {
            $data = json_decode($content, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InstallerException(sprintf('The pin file %s is not valid JSON: %s.', $file, $jsonException->getMessage()), $jsonException);
        }

        if (! is_array($data) || ! is_string($data['version'] ?? null)) {
            throw self::incomplete($file);
        }

        // The format of release 1.x pins the browser only. Its hashes cannot become a pair.
        if (array_key_exists('sha256', $data)) {
            // The message prints the version, so only a checked one reaches the terminal.
            self::checkVersion($data['version']);

            throw new InstallerException(sprintf('The pin file %s pins chrome-headless-shell only, in the format of release 1.x. Write it again with `vendor/bin/cdp-browser pin %s%s`: it pins chrome-headless-shell and chromedriver.', $file, $data['version'], self::option($file)));
        }

        $hashes = [];

        foreach (Binary::cases() as $binary) {
            if (! is_array($data[$binary->value] ?? null)) {
                throw self::incomplete($file);
            }

            foreach ($data[$binary->value] as $platform => $hash) {
                if (is_string($platform) && is_string($hash)) {
                    $hashes[$binary->value][$platform] = $hash;
                }
            }
        }

        return new self($data['version'], $hashes);
    }

    public function write(string $file): void
    {
        $data = ['version' => $this->version];

        foreach (Binary::cases() as $binary) {
            foreach (Platform::cases() as $platform) {
                $data[$binary->value][$platform->value] = $this->hash($binary, $platform);
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! Files::write(Files::target($file), $json."\n")) {
            throw new InstallerException(sprintf('The pin file %s could not be written. Check that this user can write to it and to its directory.', $file));
        }
    }

    public function hash(Binary $binary, Platform $platform): string
    {
        return $this->hashes[$binary->value][$platform->value];
    }

    private static function incomplete(string $file): InstallerException
    {
        return new InstallerException(sprintf('The pin file %s needs `version`, `%s` and `%s`.', $file, Binary::ChromeHeadlessShell->value, Binary::Chromedriver->value));
    }
}
