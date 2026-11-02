# Changelog

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
