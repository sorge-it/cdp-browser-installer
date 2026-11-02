<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller;

use JsonException;

/**
 * One version of chrome-headless-shell and the SHA-256 of its archive for each platform. Chrome
 * for Testing publishes no SHA-256 and no signature; its storage sends an MD5 and a CRC32C.
 * So `pin` compares each download with the MD5 and computes its SHA-256; a human reviews the pin
 * file.
 */
final readonly class Pin
{
    /**
     * The pin file in the working directory, where each command looks without `--pin-file`.
     */
    public const string FILE = '.chrome-version';

    /**
     * `\z`, not `$`: `$` also takes a line break at the end.
     */
    private const string VERSION = '/\A\d+\.\d+\.\d+\.\d+\z/';

    private const string SHA256 = '/\A[0-9a-f]{64}\z/';

    /**
     * @param  array<string, string>  $hashes  the SHA-256 for each value of `Platform`
     */
    public function __construct(public string $version, private array $hashes)
    {
        self::checkVersion($version);

        foreach (Platform::cases() as $platform) {
            $hash = $hashes[$platform->value] ?? '';

            if (preg_match(self::SHA256, $hash) !== 1) {
                throw new InstallerException(sprintf('The pin of %s has no valid SHA-256 for %s.', $version, $platform->value));
            }
        }
    }

    public static function checkVersion(string $version): void
    {
        if (preg_match(self::VERSION, $version) !== 1) {
            throw new InstallerException(sprintf('`%s` is not a Chrome for Testing version, such as 155.0.8059.39.', $version));
        }
    }

    public static function read(string $file): self
    {
        if (! is_file($file)) {
            throw new InstallerException(sprintf('There is no pin file at %s. Write one with `cdp-browser pin <version>`.', $file));
        }

        $content = @file_get_contents($file);

        if ($content === false) {
            throw new InstallerException(sprintf('The pin file %s could not be read.', $file));
        }

        try {
            $data = json_decode($content, true, 3, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new InstallerException(sprintf('The pin file %s is not valid JSON: %s.', $file, $jsonException->getMessage()), $jsonException);
        }

        if (! is_array($data) || ! is_string($data['version'] ?? null) || ! is_array($data['sha256'] ?? null)) {
            throw new InstallerException(sprintf('The pin file %s needs `version` and `sha256`.', $file));
        }

        $hashes = [];

        foreach ($data['sha256'] as $platform => $hash) {
            if (is_string($platform) && is_string($hash)) {
                $hashes[$platform] = $hash;
            }
        }

        return new self($data['version'], $hashes);
    }

    public function write(string $file): void
    {
        $hashes = [];

        foreach (Platform::cases() as $platform) {
            $hashes[$platform->value] = $this->hash($platform);
        }

        $json = json_encode(['version' => $this->version, 'sha256' => $hashes], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (! Files::write(Files::target($file), $json."\n")) {
            throw new InstallerException(sprintf('The pin file %s could not be written.', $file));
        }
    }

    public function hash(Platform $platform): string
    {
        return $this->hashes[$platform->value];
    }
}
