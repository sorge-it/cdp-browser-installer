# Changelog

All notable changes to this package are in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.1] - Unreleased

The API, the commands, the options and the exit codes stay as in 2.0.0. A lock timeout still ends
`install` with exit code 1, although its message now says to run `cdp-browser install` again.

### Fixed

- A missing `curl`, `unzip` or `zipinfo` names the step for the system: `cdp-browser deps` where apt is,
  `apk add curl unzip` on Alpine, where `deps` installs neither, and the system's package manager
  elsewhere. Any other missing tool names the system's package manager, not `deps`. `install`
  checks for `unzip` and `zipinfo` before safe-unzip starts them.
- An unsupported platform no longer gets the message that Chrome for Testing has no build for it,
  which was wrong for Windows. The message now says that the installer does not support the
  platform, and names the supported ones.
- More messages name the next step:
  - a lock timeout: run `cdp-browser install` again when the other install has ended, or raise
    `CDP_BROWSER_LOCK_TIMEOUT`;
  - no cache directory: set `CDP_BROWSER_PATH` to an absolute path;
  - a directory that cannot be created: check that no part of the path is a file, and that this
    user can write to its deepest existing directory;
  - a stamp or pin file that cannot be written, or a pin file that cannot be read: check the
    access of this user;
  - an archive that cannot be read during the check: run `cdp-browser install` again;
  - in `pin`, a wrong MD5 or a download that cannot be read: run `cdp-browser pin` again with the
    same version;
  - a version in another format: use four numbers;
  - a timeout in another format: use digits only.
- A missing pin file names the working directory.
- With a lock timeout of 0, the message says that another install holds the lock and the lock
  timeout is 0.
- The README corrects these statements:
  - in the comparison, `dbrekelmans/bdi` and `dusk:chrome-driver` of `laravel/dusk` pin a version
    in the command line, not "no";
  - at the tool timeout of `zipinfo` and `unzip`, `getPrevious()` returns safe-unzip's
    `ToolException`, not Symfony's `ProcessTimedOutException`;
  - any other exception ends a command with its own code, capped at 255, if that is above 0, and a
    PHP `Error` with 255, not each with exit code 1;
  - Panther reads `PANTHER_CHROME_BINARY` when the client starts, not when it is created.

### Changed

- The messages use plain English, such as "responded with HTTP status 503", "refuses to run as
  root", "but the pin expects" and, for `deps` without root, "Run it as root, such as with sudo.".
  A tool without error output ends its message with a full stop. `--version` without Composer
  prints `(unknown version: …)`. When another install removed a file, the message names shared
  file systems as the cause.
- The README has a new order of sections: "Contents", "Why this exists", "Installation", "Quick
  start", "Works with", "GitHub Actions", "Use from PHP", "Run the browser and ChromeDriver", "The
  pin file", "Security", "When to use it, and when not to", "Comparison with other packages",
  "Reference", "Troubleshooting", "Compatibility", "Development", "Support", "License",
  "Trademarks" and "About the author".
- The README drops the section "How it works"; rule 4 under "Security" keeps its checks. The
  intro list and "Built for coding agents and CI" are now "Why this exists". "Requirements" is
  part of "Installation", and "Limits of the security model" part of "Security". "Commands",
  "Environment variables", "API", "Exceptions" and "Where the pair goes", now "Cache directory",
  are part of "Reference". "Alpine and other musl systems" is part of "Compatibility", and "Tests"
  part of "Development". The text on the workflow `chrome-for-testing` moves from "The
  pin file" to "Development". "Use from PHP" drops its example without the environment and links
  to "API".
- The README drops the PHPStan badge. It showed a fixed text, not the state of a run.
- The tagline of the README is the description in `composer.json`, which Packagist shows, and a
  test keeps the two equal. The description adds Mink, "PHP browser tests" and "on glibc Linux
  and macOS", and drops "as a Composer dev dependency". The keywords add `chrome-php`, `mink`,
  `composer` and `php`, and drop `ai-agents`, `agentic-engineering`, `installer`, `github-actions`,
  `pest`, `phpunit`, `dusk`, `panther` and `chromium`.
- Each PHP example under "Works with" is a complete file that starts with `<?php`. Each script
  also requires `vendor/autoload.php`.
- The Mink example has a second command line. It waits until the browser responds on port 9222.
- The README base class for Laravel Dusk reads the pin file in the directory above `tests/`. It
  reads the environment once, in `prepare()`, and keeps the window size of Dusk's stub, 1920×1080,
  which the workflow `works-with` checks.
- The Dusk section names the line for `tests/Pest.php`. It no longer asks you to create
  `tests/Browser/console`, `tests/Browser/screenshots` and `tests/Browser/source`: Dusk creates
  them since 8.2.9.
- The README says that `composer require` needs `unzip` or `ext-zip`, which the official `php`
  Docker images on Debian lack.
- The README section "GitHub Actions" shows a whole workflow for Dusk: the one from Laravel's
  documentation, with the cache, `deps` and `install` instead of its ChromeDriver steps, and it
  names each difference. It no longer names the commit SHA of `actions/cache`.
- The comparison is as of 2026-11-18, with the newest release of each package and links to the
  lines of code behind its claims. It has no column "Made for". It names the `chromedriver` of
  `@puppeteer/browsers`, the configuration file of Puppeteer, the other browsers of Playwright and
  the Windows code of the Pest plugin.
- SECURITY.md lists the latest minor release of 2.x as supported. Release 1.x gets no more fixes.

### Added

- README sections: "Contents", "Why this exists", "Installation", "Symfony Panther with
  PantherTestCase", "Laravel Dusk on GitHub Actions", "Update the pin", "When to use it, and when
  not to", "Reference", "Troubleshooting", "Compatibility" and "Support".
- The section "Symfony Panther with PantherTestCase" shows a base class for Panther's
  `PantherTestCase`. Both jobs of the workflow `works-with` run a PHPUnit test with it.

## [2.0.0] - 2026-11-04

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

## [1.0.1] - 2026-11-02

No code changes.

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

## [1.0.0] - 2026-11-02

The first public release. The installer puts `chrome-headless-shell` from Chrome for Testing into
a cache as a Composer dev dependency, on Linux with glibc and on macOS, on x64 and arm64. A pin
file next to `composer.lock` names the version and the SHA-256 of each archive. `install` checks
the SHA-256 before each unpack, also for an archive that a CI cache restored, and unpacks with
`sorge-it/safe-unzip`. The commands are `pin`, `install`, `path` and `deps`; a PHP API serves test
tools. The README states what the installer refuses, what it checks, and what it does not check.
The 0.x releases were private.

[2.0.1]: https://github.com/sorge-it/cdp-browser-installer/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/sorge-it/cdp-browser-installer/compare/v1.0.1...v2.0.0
[1.0.1]: https://github.com/sorge-it/cdp-browser-installer/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/sorge-it/cdp-browser-installer/releases/tag/v1.0.0
