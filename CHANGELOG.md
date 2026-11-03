# Changelog

## 2.0.0 - 2026-11-04

The installer installs `chrome-headless-shell` and `chromedriver` of one Chrome for Testing version
as a pair. The two binaries have equal rank in the pin file, the cache, the commands and the PHP
API. A WebDriver tool gets a ChromeDriver of the same version as the browser.

### Upgrade from 1.x

1. Run `vendor/bin/cdp-browser pin <version>` again, and commit the new `.chrome-version`. `pin`
   downloads eight archives: one for each binary and each platform.
2. Replace `vendor/bin/cdp-browser path` with `vendor/bin/cdp-browser path chrome-headless-shell`.
3. In PHP, replace `browser()` with `binaries()`, and get the path with
   `->path(Binary::ChromeHeadlessShell)`.
4. Replace `CDP_BROWSER_BINARY` with `CDP_BROWSER_CHROME`, and set `CDP_BROWSER_CHROMEDRIVER` to
   the system's ChromeDriver. For the pinned pair, unset `CDP_BROWSER_BINARY`: while it is set and
   not empty, `install` and `path` stop with exit code 1.
5. In PHP, pass the hashes to `new Pin()` nested by binary and platform, and the system's pair to
   `new Installer()` as `Binaries`.
6. On Alpine, run `vendor/bin/cdp-browser deps` again: it installs `chromium-chromedriver`.

### Changed

- **Breaking:** The pin file holds `version`, `chrome-headless-shell` and `chromedriver`, with a
  SHA-256 for each platform under each binary. A pin file of release 1.x (`sha256`) stops
  `install` and `path` of the pinned pair with exit code 1, and `Pin::read()` and
  `Installer::binaries()` throw an `InstallerException`. The message names
  `cdp-browser pin <version>`. With the system's pair, `install` and `path` do not read the pin
  file.
- **Breaking:** `install` installs both binaries. It checks the SHA-256 of each archive before it
  unpacks the archive, and puts both binaries side by side under `<version>/<platform>/`. The pair
  counts as installed only when both binaries are installed. The stamp is `.sha256-pair`, so the
  first install after 1.x unpacks the browser again.
- **Breaking:** `path` needs the name of a binary: `path chrome-headless-shell` or
  `path chromedriver`. Without a name, it stops and names both.
- **Breaking:** The PHP API returns `Binaries`. `Installer::binaries()` replaces `browser()`,
  `Installer::paths()` replaces `binary()`, and `Installer::ownBinaries()` replaces `ownBrowser()`;
  `Installer::install()` returns `Binaries` too. `Download::url()`, `Download::to()`, `Pin::hash()`
  and `Unpack::to()` take a `Binary`. `Binary::directory()` and `Binary::path()` replace
  `Platform::BINARY`, `Platform::directory()` and `Platform::binary()`.
- **Breaking:** `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER` replace `CDP_BROWSER_BINARY`,
  and `Installer::OWN_CHROME` and `Installer::OWN_CHROMEDRIVER` replace `Installer::OWN_BROWSER`.
  Set both or neither: when only one is set, `install`, `path` and `Installer::fromEnvironment()`
  stop with exit code 1 and name the other; `pin` and `deps` read neither. With both, `install`
  and `path` download nothing. When `CDP_BROWSER_BINARY` is set and not empty, `install`, `path`
  and `Installer::fromEnvironment()` stop with exit code 1, and the message names both new
  variables. No command uses its value.
- **Breaking:** `Pin::__construct(string $version, array $hashes)` takes the hashes nested by
  binary and platform, as `array<string, array<string, string>>`; release 1.0.1 took
  `array<string, string>`, by platform only. `Installer::__construct()` takes
  `?Binaries $own = null` instead of `?string $ownBrowser = null`.
- On musl, `deps` installs `chromium-chromedriver` beside `chromium`, and the message of `install`
  and `path` names both variables. For the pinned pair, `install`, `path` and
  `Installer::binaries()` refuse on musl before they read the pin file, so a missing pin file or
  one of release 1.x gives the same message.
- `pin` stops before its first download when the pin file is a symbolic link to a missing file;
  release 1.0.1 replaced the link with a file. When `pin` cannot read a downloaded file, it stops
  with exit code 1, not 65.
- When `install` cannot read an archive during its check, or cannot set the modification time of
  an archive of the cache, it stops with exit code 1.

### Added

- The enum `Binary`, with the cases `ChromeHeadlessShell` and `Chromedriver`, and the class
  `Binaries`, which holds the path of each binary.
- The CI job `works-with` also runs `php-webdriver/webdriver`, `symfony/panther` and
  `laravel/dusk` with the pair, and checks that ChromeDriver reports the version of the pin file.
  Dusk runs in a new Laravel app, without `dusk:install`.
- The README section "Works with" gives an example for each WebDriver tool, and a test of
  `composer test` keeps each example equal to the script of the job. The section comes directly after the demo.
- The demo, its GIF and the social preview show both binaries of the pair.

## 1.0.1 - 2026-11-02

The code of the package does not change.

### Added

- The CI job `works-with` runs `chrome-php/chrome` and `dmore/chrome-mink-driver` with the
  browser of the pin file. It installs the newest release of each tool and reads a page through
  the tool. It runs with PHP 8.5 on Linux x64, Linux arm64 and macOS arm64. It runs each week,
  after each merge, and on each pull request that changes the pin file, the installer or the job.
- Each run of the job lists in its summary the version of each tool, the browser and the platform.
  Each script checks that the browser reports the version of the pin file. The script of
  `dmore/chrome-mink-driver` also checks that its own browser answers on port 9222.
- The README section "Works with" names the two tools and gives an example for each. It links to
  the job, says what a green and a red run mean, and shows the badge of the job at the top.
- A test of `composer test` compares each example under "Works with" with the script of the job:
  the PHP lines that set up the tool, the `finally` block that stops the browser, and the command
  line of the browser for `dmore/chrome-mink-driver`.
- The README section "The pin file" describes the weekly workflow `chrome-for-testing`. Its two
  jobs check each pinned SHA-256 and compare the pin with the current Stable.

## 1.0.0 - 2026-11-02

The first public release. The installer puts `chrome-headless-shell` from Chrome for Testing into
a cache as a Composer dev dependency, on Linux with glibc and on macOS, on x64 and arm64. A pin
file next to `composer.lock` names the version and the SHA-256 of each archive. `install` checks
the SHA-256 before each unpack, also for an archive that a CI cache restored, and unpacks with
`sorge-it/safe-unzip`. The commands are `pin`, `install`, `path` and `deps`; a PHP API serves test
tools. The README states what the installer refuses, what it checks, and what it does not check.
The 0.x releases were private.
