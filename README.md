# cdp-browser-installer

**Pinned headless Chrome™ for PHP browser tests, installed by Composer. No Node, no npm.**

[![License](https://img.shields.io/badge/license-MIT-blue)](https://github.com/sorge-it/cdp-browser-installer/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.4-777bb4)](https://github.com/sorge-it/cdp-browser-installer/blob/main/composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://github.com/sorge-it/cdp-browser-installer/blob/main/phpstan.neon)

`sorge-it/cdp-browser-installer` installs `chrome-headless-shell` from
[Chrome for Testing](https://googlechromelabs.github.io/chrome-for-testing/) as a Composer dev
dependency, on Linux and macOS, on x64 and arm64. A pin file next to `composer.lock` names the
version and the SHA-256 of each archive. `install` checks the SHA-256 before each unpack, also for
an archive that a CI cache restored. Every developer and every CI run gets the same headless
browser. It is the PHP counterpart of `npx @puppeteer/browsers install`.

**Built for coding agents and CI.** An agent writes the browser test, and CI installs the browser
on each run. No human looks at each download. You review the pin file once, in the pull request,
like a change to `composer.lock`. After that, each install checks the archive against it. A
command that refuses ends with a non-zero exit code and a message that names the cause and the
next step: 75 for a download that a retry can fix, 65 for a wrong SHA-256, 1 for every other cause.
The laptop and CI read the same pin file, so a test does not fail in CI only because the browser
differs.

## Quick start

```sh
composer require --dev sorge-it/cdp-browser-installer
vendor/bin/cdp-browser pin 156.0.8078.80   # writes the pin file .chrome-version; commit it
vendor/bin/cdp-browser install             # in each checkout, container and CI run
vendor/bin/cdp-browser path                # prints the path of the browser
```

Take the version from the
[Chrome for Testing dashboard](https://googlechromelabs.github.io/chrome-for-testing/). `pin`
downloads the archive for each platform and writes the version and each SHA-256. If the system
lacks `curl`, `unzip` or a library of the browser, run `vendor/bin/cdp-browser deps` as root
first. In the
[text demo](https://github.com/sorge-it/cdp-browser-installer/blob/main/demo/output/demo.txt) on
macOS arm64, the first `install` took 9.4 s with the download, the second 0.0 s.

A test tool that drives Chrome through the Chrome DevTools Protocol (CDP) starts the binary that
`path` prints, or calls the [PHP API](#use-from-php). The package contains no Pest or PHPUnit
plugin, and it installs no ChromeDriver. A project with Node can use
`npx @puppeteer/browsers install` or `npx playwright install` instead.

**Platforms.** Linux with glibc and macOS, on x64 and arm64. On Windows, Composer refuses the
package. On Alpine and other musl systems, use the system's `chromium`; its version is Alpine's,
not the pin file's (see [Alpine](#alpine-and-other-musl-systems)). `install` and `pin` refuse
to run as root without `--allow-root` (see [Commands](#commands)).

**Trust.** [Security](#security) lists seven rules and the code that implements each.
[Limits of the security model](#limits-of-the-security-model) lists what the installer does not
check. `composer test` requires PHPStan level max and 100 % type coverage; a CI job requires
100 % line coverage.

![install checks the pinned archive and installs the browser, then refuses an archive with one byte added](https://github.com/sorge-it/cdp-browser-installer/raw/main/demo/output/demo.gif)

`install` reads the version and the SHA-256 from the pin file, downloads the archive and checks it
before unpacking. The GIF skips the wait for the download. The stamp is a file beside the browser
with the SHA-256 of its archive. The demo removes the browser and its stamp, as if a CI cache had
restored only the archive. It adds one byte to the archive, and `install` deletes the archive
without unpacking it. The GIF demo runs in Docker on Debian 13, on linux-arm64.

## Run the browser

`vendor/bin/cdp-browser path` prints the path of the browser. Start it from that path, not
through a symbolic link: on macOS, Chrome looks for its data files next to the path it was started
from.

```sh
"$(vendor/bin/cdp-browser path)" --dump-dom 'data:text/html,<p>ok</p>'
```

On Ubuntu 23.10 and later, and in most containers, Chrome's sandbox finds no user namespace
and Chrome stops with `No usable sandbox!`. Start it with `--no-sandbox` there; the installer does
not decide that for you.

## The pin file

```json
{
    "version": "156.0.8078.80",
    "sha256": {
        "linux64": "547dcd374e02961bf998f6046a267644f8502225c36bb78ca307dab32a0b3eb4",
        "linux-arm64": "5bb6a00a4285c13303d3f72c5afdb70f144ea7a93eed462365a52b69631c9607",
        "mac-x64": "535d417d9034cbd11e7dacd1a71aa3d6e1b525e6d5dcd2671eb2532f01481228",
        "mac-arm64": "ea615baa2e9caf0d02d1898a6562b5f8fa9c35239707dae0f592178df5b986ac"
    }
}
```

Chrome for Testing publishes no SHA-256 and no signature: its JSON endpoints list only a platform
and a URL for each download. So `pin` computes the SHA-256 from its own download, after it has
compared the MD5 that the storage sends in `x-goog-hash`. Commit the file, and review each change
to it in the pull request, like a change to `composer.lock`: the pin is the point where you trust
Google.

## GitHub Actions

Cache the archives under a key from the hash of the pin file. The file holds the version and each
SHA-256, so a new pin gives a new key:

```yaml
env:
  CDP_BROWSER_PATH: ${{ github.workspace }}/.cache/cdp-browser

steps:
  - uses: actions/cache@v6
    with:
      path: .cache/cdp-browser/*/*/*.zip
      key: cdp-browser-${{ runner.os }}-${{ runner.arch }}-${{ hashFiles('.chrome-version') }}
  - run: sudo "$(command -v php)" vendor/bin/cdp-browser deps   # on macOS: "Nothing to do"
  - run: vendor/bin/cdp-browser install
```

To pin the action to a commit SHA, as this repository's workflow does:
`actions/cache@55cc8345863c7cc4c66a329aec7e433d2d1c52a9 # v6.1.0`.

## Use from PHP

```php
use SorgeIt\CdpBrowserInstaller\DownloadException;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

// The environment decides: CDP_BROWSER_BINARY, CDP_BROWSER_PATH, XDG_CACHE_HOME, HOME,
// CDP_BROWSER_TIMEOUT, CDP_BROWSER_LOCK_TIMEOUT.
$browser = static fn (): string => Installer::fromEnvironment(getenv())->browser(Pin::FILE, Platform::current());

try {
    $binary = $browser();
} catch (DownloadException) {
    // A retry can help, as after a network error or a 5xx status.
    $binary = $browser();
}
```

`browser()` checks and returns the system's browser, or returns the path of the pinned one and
installs it first if it is missing. A `ChecksumException` and every other `InstallerException`
reach the caller (see [Exceptions](#exceptions)).

Without the environment, pass each value to the constructors:

```php
use SorgeIt\CdpBrowserInstaller\CommandLine;

$installer = new Installer(new CommandLine(timeout: 300), '/srv/cache/cdp-browser', lockTimeout: 60);
$binary = $installer->install(Pin::read('.chrome-version'), Platform::current());
```

[API](#api) lists the classes and members that SemVer covers.

## Comparison with Puppeteer, Playwright, Laravel Dusk and Symfony Panther

Chrome for Testing's README names
[`@puppeteer/browsers`](https://github.com/GoogleChromeLabs/chrome-for-testing/blob/f0abac9d5b65cc206b06d1707ecda7a57f094069/README.md#L152-L154)
as "the easiest way" to download it. That package, Puppeteer and Playwright run on Node. The PHP
packages for browser tests install a driver, use a browser that is already installed, or rely on
Playwright or Puppeteer from npm. As of 2026-11-02, from the source code of each version:

| Package | Version | Made for | Installs | Node | Pins a version | Checks a SHA-256 | Locks a parallel install | Windows |
|---|---|---|---|---|---|---|---|---|
| [**`sorge-it/cdp-browser-installer`**](https://github.com/sorge-it/cdp-browser-installer) | this README | installing a pinned, SHA-256-checked `chrome-headless-shell` as a Composer dev dependency | `chrome-headless-shell` from Chrome for Testing, for Linux and macOS on x64 and arm64 | no | yes, in the pin file | yes, before each unpack | yes, for each version and platform | no: Composer refuses it, as it requires `ext-posix` |
| [`@puppeteer/browsers`](https://www.npmjs.com/package/@puppeteer/browsers) | 3.2.6, 2026-10-22 | downloading and launching browsers | `chrome-headless-shell` and other browsers, by build or by alias | 22.12 or later | in the command line, not in a file: the caller names a build or an alias | when the caller of the API passes `expectedHash`; the CLI passes none | no | yes: `win32` and `win64` |
| [`puppeteer`](https://www.npmjs.com/package/puppeteer) | 25.16.0, 2026-10-22 | controlling headless Chrome over the DevTools Protocol | `chrome` and `chrome-headless-shell`, through `@puppeteer/browsers`, on `npm install` | 22.12 or later | yes, one version for each release | no | no | yes, through `@puppeteer/browsers` |
| [`playwright-core`](https://www.npmjs.com/package/playwright-core) | 1.64.1, 2026-10-14 | automating web browsers | `chromium-headless-shell`, from its own mirror `cdn.playwright.dev` | 20 or later | yes, in `browsers.json` | no | yes | yes: `win64` |
| [`pestphp/pest-plugin-browser`](https://packagist.org/packages/pestphp/pest-plugin-browser) | 5.2.0, 2026-10-29 | testing browser interactions in Pest | no browser; its message asks for `npm install playwright && npx playwright install` | yes, for Playwright from npm | — | — | — | not stated; its CI runs on Ubuntu only |
| [`dbrekelmans/bdi`](https://packagist.org/packages/dbrekelmans/bdi) | 1.4.1, 2025-11-03 | installing browser drivers | ChromeDriver and GeckoDriver | no | no: the latest, or the one that matches the browser it finds | no | no | yes: drivers for Windows |
| [`laravel/dusk`](https://packagist.org/packages/laravel/dusk) | 8.7.2, 2026-10-07 | end-to-end tests and browser automation for Laravel | ChromeDriver | no | no: the stable one, an argument, or with `--detect` the one that matches the installed Chrome | no | no | yes: ChromeDriver for `win32` |
| [`symfony/panther`](https://packagist.org/packages/symfony/panther) | 2.4.0, 2026-01-08 | browser tests and web scraping in PHP and Symfony | no browser; it names `bdi` for the drivers | no | — | — | — | yes: a CI job on `windows-latest` |
| [`chrome-php/chrome`](https://packagist.org/packages/chrome-php/chrome) | 1.16.1, 2026-07-06 | controlling headless Chrome from PHP | no browser; it finds a Chrome by `CHROME_PATH` or a system path | no | — | — | — | yes: it reads the path of Chrome from the registry |
| [`spatie/browsershot`](https://packagist.org/packages/spatie/browsershot) | 5.4.2, 2026-10-26 | images and PDFs of web pages, through headless Chrome | no browser; it uses the npm package `puppeteer` | 22 or later | — | — | — | yes: it starts Node in its own way on Windows |

<details>
<summary>Sources of the comparison</summary>

"Made for" follows the description of each package on npm or Packagist. The sources for the other
columns:

- `sorge-it/cdp-browser-installer`: rules 1, 3 and 5 under [Security](#security);
  `ext-posix` in [`composer.json`](https://github.com/sorge-it/cdp-browser-installer/blob/main/composer.json).
- `@puppeteer/browsers`: [`install.ts#L114-L118`](https://github.com/puppeteer/puppeteer/blob/browsers-v3.2.6/packages/browsers/src/install.ts#L114-L118)
  ("If omitted, the download proceeds without integrity verification"); `src/CLI.ts` and the
  install of `puppeteer` ([`install.ts`](https://github.com/puppeteer/puppeteer/blob/puppeteer-v25.16.0/packages/puppeteer/src/node/install.ts))
  pass no hash. The pin of `puppeteer`: [`revisions.ts#L11-L12`](https://github.com/puppeteer/puppeteer/blob/puppeteer-v25.16.0/packages/puppeteer-core/src/revisions.ts#L11-L12).
  Windows: [`types.ts#L31-L32`](https://github.com/puppeteer/puppeteer/blob/browsers-v3.2.6/packages/browsers/src/browser-data/types.ts#L31-L32).
- `playwright-core`: [`browsers.json#L12-L16`](https://github.com/microsoft/playwright/blob/v1.64.1/packages/playwright-core/browsers.json#L12-L16),
  the mirror in [`registry/index.ts#L48-L50`](https://github.com/microsoft/playwright/blob/v1.64.1/packages/playwright-core/src/server/registry/index.ts#L48-L50)
  and [`#L117-L122`](https://github.com/microsoft/playwright/blob/v1.64.1/packages/playwright-core/src/server/registry/index.ts#L117-L122),
  the lock in [`#L937-L953`](https://github.com/microsoft/playwright/blob/v1.64.1/packages/playwright-core/src/server/registry/index.ts#L937-L953);
  `src/server/registry/` contains no `createHash`, `sha256`, `checksum` or `integrity`. Windows:
  [`#L198`](https://github.com/microsoft/playwright/blob/v1.64.1/packages/playwright-core/src/server/registry/index.ts#L198).
- `pestphp/pest-plugin-browser`: [`PlaywrightNotInstalledException.php#L22`](https://github.com/pestphp/pest-plugin-browser/blob/v5.2.0/src/Exceptions/PlaywrightNotInstalledException.php#L22);
  the CI matrix in [`tests.yml#L13`](https://github.com/pestphp/pest-plugin-browser/blob/v5.2.0/.github/workflows/tests.yml#L13).
- `dbrekelmans/bdi`: [`README.md#L33-L36`](https://github.com/dbrekelmans/browser-driver-installer/blob/1.4.1/README.md#L33-L36).
  Windows: [`ChromeDriver/Downloader.php#L173`](https://github.com/dbrekelmans/browser-driver-installer/blob/1.4.1/src/Driver/ChromeDriver/Downloader.php#L173).
- `laravel/dusk`: [`ChromeDriverCommand.php#L191-L212`](https://github.com/laravel/dusk/blob/v8.7.2/src/Console/ChromeDriverCommand.php#L191-L212)
  writes the download through Guzzle and checks its status; `--detect` in
  [`#L26-L28`](https://github.com/laravel/dusk/blob/v8.7.2/src/Console/ChromeDriverCommand.php#L26-L28).
  Windows: [`OperatingSystem.php#L46-L47`](https://github.com/laravel/dusk/blob/v8.7.2/src/OperatingSystem.php#L46-L47).
- `symfony/panther`: [`ChromeManager.php#L93`](https://github.com/symfony/panther/blob/v2.4.0/src/ProcessManager/ChromeManager.php#L93).
  Windows: [`ci.yml#L123-L125`](https://github.com/symfony/panther/blob/v2.4.0/.github/workflows/ci.yml#L123-L125).
- `chrome-php/chrome`: [`AutoDiscover.php#L33-L47`](https://github.com/chrome-php/chrome/blob/v1.16.1/src/AutoDiscover.php#L33-L47).
- `spatie/browsershot`: [`docs/requirements.md#L6`](https://github.com/spatie/browsershot/blob/5.4.2/docs/requirements.md#L6).
  Windows: [`Browsershot.php#L1107`](https://github.com/spatie/browsershot/blob/5.4.2/src/Browsershot.php#L1107).

</details>

## Requirements

- PHP 8.4 or later, with `posix` and `proc_open()`. No `ext-zip`, no Node.
- `curl`, plus Info-ZIP UnZip 6.00 and ZipInfo 3.00, which both come with the `unzip` package.
  safe-unzip checks the version of both tools before it reads an archive. macOS ships `curl` and
  `unzip`; on Debian and Ubuntu, `deps` installs them.
- Linux with glibc, or macOS, on x64 or arm64. On musl, as on Alpine, the installer uses the
  system's `chromium` instead (see [Alpine](#alpine-and-other-musl-systems)). Composer refuses to install the package
  on Windows: the package requires `ext-posix`, and PHP has no `ext-posix` on Windows. On other
  platforms, the installer stops with an `InstallerException`.

Ubuntu 24.04 ships PHP 8.3; there, install PHP 8.4, for example from `ppa:ondrej/php`.

---

The rest of this file is reference:
[How it works](#how-it-works) · [Security](#security) ·
[Limits of the security model](#limits-of-the-security-model) · [Commands](#commands) ·
[Environment variables](#environment-variables) · [Where the browser goes](#where-the-browser-goes) ·
[Alpine](#alpine-and-other-musl-systems) · [API](#api) · [Exceptions](#exceptions) ·
[Tests](#tests) · [Development](#development)

## How it works

Native tools do the work; PHP decides and checks. `curl` downloads, `zipinfo` lists and `unzip`
unpacks. Each starts from an argument list that you can read in
[`Download`](https://github.com/sorge-it/cdp-browser-installer/blob/main/src/Download.php) and
[`sorge-it/safe-unzip`](https://github.com/sorge-it/safe-unzip). Composer unpacks a package the
same way: its `ZipDownloader` tries the `unzip` binary first and falls back to `ZipArchive` only
when `unzip` is missing, because `ZipArchive` loses the Unix modes. A browser without its
executable bit does not start.

Composer trusts `unzip`. This installer adds checks before and after it:

| Step | Native tool | PHP checks |
|---|---|---|
| Download | `curl -q --proto =https --tlsv1.2 --max-filesize …`, without `--location` | the status is 200; a failed download leaves no archive; `pin` compares the MD5 from `x-goog-hash` |
| Archive | — | the SHA-256 of the pin file, before each unpack |
| Before unpacking | `zipinfo -h`, `zipinfo -v` | each line against a closed grammar, then safe-unzip's "standard ZIP" profile: methods, hosts, flags, extra fields, names, types, counts, offsets, and fixed header fields read at the offsets of the listing |
| Check pass | `unzip -p` | each entry decompresses into a pipe and has the size and the CRC-32 of the listing; nothing is written |
| Unpacking | `unzip -q` | the written tree holds exactly the files of the listing, with their sizes and CRC-32, and no link; safe-unzip sets each mode without write access for group and others |
| Into place | `rm -rf` of the temporary directories | a lock per version and platform, the old browser aside, the new one in, one rename each |

The installer unpacks with `SorgeIt\SafeUnzip\ZipArchive` from
[`sorge-it/safe-unzip`](https://github.com/sorge-it/safe-unzip): the reading interface of PHP's
`\ZipArchive`, on top of `unzip` and `zipinfo`. It needs no `ext-zip`, keeps the Unix modes, checks
before and after `unzip` writes, and throws a subclass of `UnzipException` instead of returning
`false`. Its README lists each check and each exception. It knows nothing about Chrome. The
installer wraps each such exception in an `InstallerException`, as its previous exception (see
[Exceptions](#exceptions)).

## Security

The package protects the download: the archive from the one known source, with the pinned
SHA-256. The host is responsible for the rest: whoever can write to the cache, `PATH` or PHP's
environment decides what runs.

Each rule names the code that implements it. A test in `tests/` proves each rule except rule 7.

1. **Pinned.** One version, one SHA-256 for each platform, in a file in your repository (`Pin`).
   `pin` first compares each download with the MD5 in `x-goog-hash` (`PinCommand`).
2. **One source.** Downloads come only from `https://storage.googleapis.com/chrome-for-testing-public/`
   (`Download::SOURCE`). `curl -q` reads no `.curlrc`. `--proto =https --tlsv1.2` allows only
   HTTPS with TLS 1.2 or later, and TLS verification stays on. `--max-filesize` stops at 500 MiB.
   Any status other than 200 stops the install; without `--location`, this includes a redirect.
   A failed download, including one stopped by a
   timeout, leaves no archive: `install` downloads into a `.part-*` file of the run and deletes
   it. If it cannot, as in a directory that turned read-only, the file stays until an install
   that unpacks removes it, 120 seconds after its last write (rule 5). `pin` removes its own
   directory with each download in it.
3. **Checked before unpacked.** A SHA-256 that differs from the pin file stops the install and
   deletes the archive (`Installer::verify`). The check runs once before each unpack: on a new
   download, and on an archive that a CI cache restored. The install checks and unpacks a file
   of its own: it downloads into a `.part-*` file, or renames the restored archive to one, and
   moves the file back into the cache after the unpack. A browser whose binary and stamp match
   the pin file counts as installed, and its archive is not read again.
4. **No entry outside the target.** safe-unzip parses `zipinfo -v` with a closed grammar and
   checks the archive against its "standard ZIP" profile before `unzip` writes a byte. The install
   stops at any of these: a line that the grammar does not know, a method other than store and
   deflate, a host other than Unix, FAT and NTFS, an encrypted entry, a symbolic link or device, a
   file with the name of another entry's directory, a name with a character outside
   `A-Z a-z 0-9 . _ - /` or a `.` or `..` segment, two entries with the same name, a local header
   that differs from its central header, or overlapping entries. It also stops at more than
   10,000 entries, counted by `zipinfo -h` before the listing, and at more than 2 GiB unpacked
   (safe-unzip's default `Limits`). Before `unzip` writes, a check pass compares the size and the
   CRC-32 of each entry with the listing, so no zip bomb reaches the disk. After `unzip`, the
   temporary directory must hold exactly the files of the listing, with their sizes and CRC-32,
   and no link. Every tool runs with `LC_ALL=C`, and without the variables `UNZIP`, `UNZIPOPT`,
   `ZIPINFO` and `ZIPINFOOPT`, which would add options from the environment.
5. **One install at a time.** A lock file per version and platform makes a second install wait
   for the first one, and then find the browser installed. An install that finds the browser
   installed takes no lock and leaves the files of a stopped run in place; the next run that
   unpacks removes them. `install` replaces a browser of the same version when the binary or its
   stamp is missing, or when the stamp holds the SHA-256 of another archive. Then the old tree
   moves aside and the new one moves in, with one rename each, and the old tree is removed
   (`Installer`). A browser that still runs from the old tree keeps only the files it has open.
   Where the lock does not exclude a second process, as on a Docker Desktop bind mount on macOS,
   each install downloads its own file, checks it and unpacks it. Another archive that a second
   run puts at the path of the cache meanwhile does not change what the first run unpacks. An
   install that finds the browser installed after it has unpacked keeps that browser and removes
   its own tree.
6. **Root only for the package manager.** Only `deps` needs root. It installs a fixed package
   list from the system's signed sources (`SystemLibraries`). It finds `apk` or `apt-get` in
   `PATH`, passes the caller's environment plus `DEBIAN_FRONTEND=noninteractive`, and runs it
   without a timeout, so that no timeout leaves dpkg half configured; Ctrl-C or SIGKILL still can.
   The commands `install` and `pin` stop as root without `--allow-root` (`Cli::refuseRoot`);
   `path` and `install` with `CDP_BROWSER_BINARY` write nothing; `path` and the PHP API do not
   check the user.
7. **No shell.** Every process starts from an argument list (`CommandLine`); no test scans the
   code for a shell call. The installer looks a tool up in the `PATH` that the process gets, where
   a relative or empty entry counts from the working directory; without a `PATH`, it looks in
   `/usr/bin` and `/bin`. safe-unzip looks for `zipinfo` and `unzip` in the absolute entries only.
   A timeout or a signal stops the install like a failing exit code.

## Limits of the security model

- **Trust on first use.** `pin` trusts the bytes that Google serves at that moment. The SHA-256
  detects every later change, but not a wrong first download. Google Cloud Storage sends an MD5
  and a CRC32C of each archive in the `x-goog-hash` header. `pin` compares the MD5 with its
  download and writes no pin file when they differ. For a platform without an MD5, it prints one
  line that says so, and still writes the pin file. The MD5 and the archive come from the same
  server: the MD5 detects a broken download, not a changed source.
- **The lock on a shared file system.** On a Docker Desktop bind mount on macOS, `flock()` did not
  exclude a second process (measured on 2026-10-30). Rule 5 keeps the browser intact there, but
  each install downloads the archive. NFS and bind mounts on a Linux host are not measured.
- **No later check of an installed browser.** The SHA-256 covers the archive before the unpack,
  not the files after it. A changed file of an installed browser stays undetected while its
  binary and stamp match the pin file. To check it again, remove `<cache>/<version>/` and run
  `install`.
- **Rosetta.** `Platform::current()` reads `php_uname('m')`. An x64 build of PHP that runs through
  Rosetta 2 on Apple silicon reports `x86_64` and installs `mac-x64`, which then also runs through
  Rosetta. Not measured; use an arm64 build of PHP.
- **Older distributions.** `deps` uses the package names from after the 64-bit time transition,
  such as `libasound2t64`. They exist on Debian 13 and on Ubuntu 24.04 and later; CI checks the
  list on minimal images of Debian 13 and Ubuntu 26.04. On an older release, apt stops at the
  first unknown name, and so does `deps`. There, install the libraries that
  `ldd chrome-headless-shell` lists yourself.
- **Two timeouts.** The lock timeout (600 seconds) covers the whole install of the other run: a
  download and each run of `zipinfo` and `unzip`, each of which can take up to the tool timeout
  (900 seconds). On a slow connection, a second install can stop at the lock while the first one
  is still running. There, set `CDP_BROWSER_LOCK_TIMEOUT` above the duration of one install.
- **Old versions stay.** A new pin installs into a new directory `<cache>/<version>/`. No command
  removes the directory of an old version; remove it yourself.

## Commands

| Command | Does | Runs as |
|---|---|---|
| `pin <version>` | Checks that it can write the pin file and that the real path of its temporary directory has no refused character, then downloads the archive for each platform, compares its MD5 with the one the storage sends, unpacks it once with the checks of `install`, and writes the version and each SHA-256 to the pin file | a normal user; root with `--allow-root` |
| `install` | Installs the pinned version for this platform, or checks the browser in `CDP_BROWSER_BINARY`, and prints the path in its message; a script reads the path from `path` | a normal user; root with `--allow-root`, or with `CDP_BROWSER_BINARY` |
| `path` | Prints the path of the browser; it writes nothing | any user, including root |
| `deps` | Installs `curl`, `unzip` and the browser's libraries through apt, or Alpine's `chromium` through apk; on macOS it does nothing. For the systems, see [Older distributions](#limits-of-the-security-model) | root |

| Option | Commands | Does |
|---|---|---|
| `--pin-file <file>` | `pin`, `install`, `path` | the pin file; the default is `.chrome-version` in the working directory |
| `--allow-root` | `pin`, `install` | lets the command run as root |
| `--version` | the application | prints the version that Composer installed, or why it knows none |
| `-v` | each command | on an error: Symfony's format, with the class, file, line, trace and usage |

A command that stops prints the message of its [exception](#exceptions) to the error output,
unchanged, and ends with the `EXIT_CODE` of its class; 75 and 65 come from `sysexits.h`:

| Exit code | Class | A script can |
|---|---|---|
| 75 (`EX_TEMPFAIL`) | `DownloadException` | run the command again |
| 65 (`EX_DATAERR`) | `ChecksumException` | stop: the bytes are not the pinned ones |
| 1 | `InstallerException` | stop: a human fixes the cause in the message |

Any other exception, such as a bug, is shown in Symfony's format and ends with exit code 1.
`install` prints `Download <url>` before each download, as `pin` does.

`install` and `pin` stop when they run as root: a browser or a pin file owned by root breaks the
next run as a normal user, who cannot replace it. In a container where only root runs, as in a
Dockerfile `RUN`, `--allow-root` accepts that risk:

```dockerfile
RUN vendor/bin/cdp-browser deps && vendor/bin/cdp-browser install --allow-root
```

A later run as another user, such as the tests, finds the browser installed and needs no write
access to the cache.

No environment variable sets this option; add it to the command line. Only the commands `install`
and `pin` refuse root: `path` writes nothing, and `Installer::install()` from PHP, as in a test
tool, runs as any user, including root. With `CDP_BROWSER_BINARY`, `install` only checks the
system's browser and writes nothing, so it runs as root without `--allow-root`.

## Environment variables

| Variable | Default | Does |
|---|---|---|
| `CDP_BROWSER_BINARY` | none | an absolute path to the system's browser, such as `/usr/bin/chromium`; `install` then downloads nothing and needs no cache |
| `CDP_BROWSER_PATH` | `$XDG_CACHE_HOME/cdp-browser` if `XDG_CACHE_HOME` is absolute, else `~/.cache/cdp-browser` | the cache directory (see below) |
| `CDP_BROWSER_TIMEOUT` | `900` | the timeout in seconds for each tool that `install`, `path` and `pin` start: one `curl` download, each run of `zipinfo` and `unzip`, and `--version` of `CDP_BROWSER_BINARY`. `deps` has no timeout |
| `CDP_BROWSER_LOCK_TIMEOUT` | `600` | how many seconds an install waits for another install in the same version directory, `<cache>/<version>/<platform>/`. `install` and `path` read it together with the cache; with `CDP_BROWSER_BINARY` set, and in `pin` and `deps`, it is not read |

Both timeouts take a whole number of seconds above 0, in digits only, without a sign, a leading
zero or a space: `900`, not `15m`, `1e3`, `+900`, ` 900` or `0900`. `0` is refused: it would mean
no limit for a tool but no wait at the lock, two opposite things for the same value. Any other
value stops the command that reads it with an `InstallerException`. An empty variable counts as
not set, as with `CDP_BROWSER_PATH`. `curl` also stops on its own when the speed stays below
1 KiB/s for 60 seconds.

## Where the browser goes

`install` puts the browser under `<cache>/<version>/<platform>/`, next to the archive it came
from:

1. `CDP_BROWSER_PATH`, if set (a relative path is resolved against the working directory),
2. else `$XDG_CACHE_HOME/cdp-browser`, if `XDG_CACHE_HOME` is absolute; as the XDG Base
   Directory Specification asks, a relative value, also one that starts with `~`, is ignored,
3. else `~/.cache/cdp-browser`.

`.` and `..` are resolved textually, without following symbolic links: `link/../cache` is
`cache` in the working directory. The root directory as the cache, and a `CDP_BROWSER_PATH` or
`HOME` that starts with `~`, stop the install: only a shell expands `~`; a `.env` file or a
Dockerfile does not. A cache path with `*`, `?`, `[`, `]`, `\` or a line break stops it before the
download, also where a symbolic link in the path leads to such a name: `unzip` reads the first
five as a pattern, and `zipinfo` prints a line break as two lines, so safe-unzip would refuse the
real path of the archive (see [How it works](#how-it-works)). `pin` refuses these
characters in the real path of its temporary directory, from `TMPDIR` or `sys_temp_dir`, before
the first download. The constructor of `Installer` checks its cache directory the same way as
`install`, and takes no relative path. With `CDP_BROWSER_BINARY`, the installer needs no cache
directory; a relative path in `CDP_BROWSER_BINARY` stops the command.

In a container, set `CDP_BROWSER_PATH` to a directory in the project, so that the next container
finds the browser of the previous one. To start the browser, see
[Run the browser](#run-the-browser).

## Alpine and other musl systems

Chrome for Testing is built against glibc and does not start on musl, not even with `gcompat`.
Measured on 2026-10-31 on Alpine 3.24.3, aarch64, with musl 1.2.6, gcompat 1.1.0 and the libraries
of the chromium package: `chrome-headless-shell` 155.0.8059.39 for `linux-arm64` stops with exit
code 127 and "unsupported relocation type 1032", and glibc symbols such as `__memcpy_chk` are
missing. Playwright does not support Alpine; Puppeteer, Symfony Panther, chrome-php and
Browsershot use Alpine's `chromium` and an environment variable with its path. This installer
does the same:

```sh
vendor/bin/cdp-browser deps                                  # as root: apk add chromium nss freetype harfbuzz ttf-freefont
CDP_BROWSER_BINARY=/usr/bin/chromium vendor/bin/cdp-browser install
```

Without `CDP_BROWSER_BINARY`, `install` detects musl (`getconf`, then the loader in the memory
maps of the PHP process, then the loader file) and stops before it downloads; its message names
the two commands above. `path` stops with the same message. The version of `chromium` is
Alpine's, not the pin file's: on the same day, Alpine 3.24 shipped `chromium` 155.0.8059.84. On a
musl system without apk, `deps` stops; install Chromium yourself there and set
`CDP_BROWSER_BINARY` to its path.

`path` then prints the path of that `chromium`. It is the full Chromium, not the headless shell.
Without a display, start it with `--headless`:

```sh
/usr/bin/chromium --headless --no-sandbox --dump-dom 'data:text/html,<p>ok</p>'
```

## API

These classes and members are the API; SemVer covers them. Only a new major release breaks them,
and the CHANGELOG marks each break as **Breaking**; Composer's `^1.0` takes no such release:

```php
namespace SorgeIt\CdpBrowserInstaller;

final readonly class Installer
{
    public const string OWN_BROWSER = 'CDP_BROWSER_BINARY';
    public const string TIMEOUT = 'CDP_BROWSER_TIMEOUT';
    public const string LOCK_TIMEOUT = 'CDP_BROWSER_LOCK_TIMEOUT';

    public function __construct(CommandLine $commandLine, ?string $cache, ?string $ownBrowser = null, ?bool $musl = null, int $lockTimeout = 600);
    /** @param array<string, string> $environment */
    public static function fromEnvironment(array $environment, CommandLine $commandLine = new CommandLine, ?bool $musl = null): self;
    /** @param array<string, string> $environment */
    public static function cache(array $environment): string;
    /** @param (\Closure(string): void)|null $onDownload gets the URL before each download */
    public function browser(string $pinFile, Platform $platform, ?\Closure $onDownload = null): string;
    /** @param (\Closure(string): void)|null $onDownload gets the URL before each download */
    public function install(Pin $pin, Platform $platform, ?\Closure $onDownload = null): string;
    public function binary(Pin $pin, Platform $platform): string;
    public function isInstalled(Pin $pin, Platform $platform): bool;
    public function ownBrowser(): ?string;
}

final readonly class CommandLine
{
    /** @param array<string, string> $environment added to the environment of PHP for each tool */
    public function __construct(array $environment = [], int $timeout = 900);
}

final readonly class Pin
{
    public const string FILE = '.chrome-version';
    public string $version;

    /** @param array<string, string> $hashes the SHA-256 for each value of Platform */
    public function __construct(string $version, array $hashes);
    public static function checkVersion(string $version): void;
    public static function read(string $file): self;
    public function write(string $file): void;
    public function hash(Platform $platform): string;
}

enum Platform: string
{
    case LinuxX64 = 'linux64';
    case LinuxArm64 = 'linux-arm64';
    case MacX64 = 'mac-x64';
    case MacArm64 = 'mac-arm64';

    public const string BINARY = 'chrome-headless-shell';

    public static function detect(string $family, string $machine): self;
    public static function current(): self;
    public function directory(): string;
    public function binary(): string;
}

class InstallerException extends \RuntimeException { public const int EXIT_CODE = 1; }
final class DownloadException extends InstallerException { public const int EXIT_CODE = 75; }
final class ChecksumException extends InstallerException { public const int EXIT_CODE = 65; }
```

`$musl = null` detects musl as `fromEnvironment()` does; `true` or `false` sets it. The code of
each exception is its `EXIT_CODE`.

Timeouts are in seconds: `0` means no limit for a tool, and no wait at the lock. A value below 0
throws an `InstallerException`. `fromEnvironment()` takes the timeout of `$commandLine` and 600
seconds for the lock, unless the environment variables set other values.

Each tool gets PHP's environment plus the variables in `$environment`: `curl`, `rm`, `getconf`,
the package manager, `CDP_BROWSER_BINARY`, and `zipinfo` and `unzip` through safe-unzip.

The rest is `@internal` and can change in any release: the command classes of `bin/cdp-browser`
(`Console\*`), the steps of an install (`Download`, `Unpack`, `Libc`, `SystemLibraries`,
`Environment`, `Files`), every method of `CommandLine`, `Installer::onMusl()`, and the
constructors of the three exceptions. The API covers what a test tool calls: find, install and
check a browser, and tell a failed download from a wrong SHA-256. How the installer starts a tool
or unpacks an archive is not part of it. `CommandLine` is part of the API only for its
constructor, the one place where a caller sets the environment and the timeout of the tools.

A new subclass of `InstallerException` comes only in a minor release, and the CHANGELOG names it.

## Exceptions

Every exception that the API throws is an `InstallerException` (a `RuntimeException`) or one of
its two subclasses. Its message names the path or the value, and the next step. Callers catch it;
only the package throws it. Where safe-unzip, Symfony or PHP threw first, `getPrevious()` returns
that exception, one level deep: for the tool timeout (`CDP_BROWSER_TIMEOUT`), it is Symfony's
`ProcessTimedOutException`. An exit code has no exception behind it, including `curl`'s exit code
28 at its own limits; then `getPrevious()` is `null`.

| Class | When | What the caller can do |
|---|---|---|
| `DownloadException` | `curl` stops with an exit code, a signal or the tool timeout, or the source answers with a status other than 200 or 404; no archive is left, only a `.part-*` file that the run could not delete (see rule 2 under [Security](#security)) | a retry can help after a network error or a 5xx status; the message names the status or the exit code. Only a 3xx status names the redirect |
| `ChecksumException` | the SHA-256 of the archive differs from the pin file; nothing is unpacked. In `pin`: the MD5 of a download differs from the one in `x-goog-hash`; no pin file is written | after a new download, a retry does not help: the bytes are not the pinned ones. If the run deleted an archive from the cache, a retry downloads it again. The message names what happened to the archive. In `pin`, a retry downloads again; the message names the platform and both MD5 values |
| `InstallerException` | every other cause: a value of the environment, an option or an argument; the user; the pin file; a version that the source does not have for the platform (status 404), such as a wrong version for `pin`; the platform or musl; `CDP_BROWSER_BINARY`; a missing tool, also `curl`; a tool other than `curl` that stops; safe-unzip refuses the archive or finds it broken, or the archive holds no binary of the platform; the file system; the lock of another install; `path` before `install` | a human fixes the cause that the message names |

`install` unpacks only after the SHA-256 has matched the pin file, and `pin` computes the SHA-256
from the same bytes. So a refusal by safe-unzip is an `InstallerException`, not a
`ChecksumException`. safe-unzip's `RefusedException`, available through `getPrevious()`, has its own `$reason`: the
rule of the profile.

A failed removal in a `finally` does not replace the first error: the first error names the
cause, and the next install removes what the run left.

PHP's `TypeError` and `ValueError` are not the package's exceptions: they mark a call that
violates the signature, such as a path with a NUL byte. Each step of the API suppresses the
warning of a failed call of the file system and throws its own exception. So an error handler that
turns PHP warnings into an `ErrorException`, as Laravel's does, still gets the package's
exception. The tests run each such step under one; for `chmod()` and `unlink()` in the write of a
stamp or a pin file, which a test cannot make fail, they put failing functions in their place.

## Tests

```sh
composer test           # pint, rector, PHPStan level max, type coverage 100 %, every suite
composer test:coverage  # line coverage 100 %, with pcov; a CI job
```

The suite runs on Symfony 8: Pest 5 requires `symfony/process` 8.1. `composer.json` also allows
Symfony 7.4.8 or later. The CI job `symfony-7` installs the package at the lowest versions,
without its development packages: Symfony 7.4.8. It runs `deps`, `install`, `path`, the
browser's `--version`, and the refusal of an archive with one byte added.

## Development

Report a bug or request a feature with a pull request, not an issue; a pull request with a
failing test is enough. How to run the checks and record the demo:
[CONTRIBUTING.md](https://github.com/sorge-it/cdp-browser-installer/blob/main/CONTRIBUTING.md).
Report a vulnerability privately through GitHub; see
[SECURITY.md](https://github.com/sorge-it/cdp-browser-installer/blob/main/SECURITY.md).

## License

MIT, see [LICENSE](https://github.com/sorge-it/cdp-browser-installer/blob/main/LICENSE). Chrome for Testing has its own license; the archive contains it as
`LICENSE.headless_shell`.

## About the author

**Stefan Sorge** · PHP expert, builder, agentic engineer.
25 years of PHP and tech for 40+ startups. I build tools that humans and AI agents
read and act on the same way.

Also by me:

- [safe-unzip](https://github.com/sorge-it/safe-unzip): the ZIP extraction this installer unpacks
  with.
- [phpunit-pest-html-assertions](https://github.com/sorge-it/phpunit-pest-html-assertions): HTML
  assertions for PHPUnit and Pest that ask the DOM, not the string.

[GitHub](https://github.com/sorge-it) · [LinkedIn](https://www.linkedin.com/in/stefansorge/)

## Trademarks

Chrome™ and Chromium™ are trademarks of Google LLC. This project names them only to identify
these browsers:

- Chrome for Testing, the browser that it installs,
- the system's Chromium, which it uses on musl,
- the browsers in the comparison.

This project is not affiliated with, endorsed by or sponsored by Google.
