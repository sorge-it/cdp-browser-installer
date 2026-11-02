# Changelog

## 1.0.0 - 2026-11-02

The first public release. The installer puts `chrome-headless-shell` from Chrome for Testing into
a cache as a Composer dev dependency, on Linux with glibc and on macOS, on x64 and arm64. A pin
file next to `composer.lock` names the version and the SHA-256 of each archive. `install` checks
the SHA-256 before each unpack, also for an archive that a CI cache restored, and unpacks with
`sorge-it/safe-unzip`. The commands are `pin`, `install`, `path` and `deps`; a PHP API serves test
tools. The README states what the installer refuses, what it checks, and what it does not check.
The 0.x releases were private.
