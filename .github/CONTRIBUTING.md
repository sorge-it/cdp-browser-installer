# Contributing

Report a bug or request a feature with a pull request: issues are turned off for this repository.
A pull request with a failing test is enough, even without a fix. Vulnerabilities are the
exception: report them privately, see [SECURITY.md](SECURITY.md).

## Setup

Clone the repository and run `composer install`. The checks need PHP 8.4 or later with
`ext-mbstring` and `ext-zip`, as `require-dev` in `composer.json` names them, and `curl`, `unzip`
and `zipinfo`, as [Installation](../README.md#installation) names them. The code must run on
PHP 8.4, on glibc Linux, on musl and on macOS.

## Tests

```sh
composer test           # pint, rector, PHPStan level max, type coverage 100%, every suite;
                        # each tool checks tests/ as well; the type coverage does not count
                        # the return types of functions and closures
composer test:coverage  # line coverage 100%, with pcov; a CI job
composer lint           # runs rector, then pint, and fixes what they find
```

To run mutation testing by hand: `vendor/bin/pest --mutate --everything --covered-only`, without
`--parallel`: with `--parallel`, Pest counted every mutant as caught, while a serial run of the
same code found untested ones. Pest's stream wrapper makes the tests of `flock()` and of unreadable
files fail without any mutant, so a mutant there that counts as caught may have escaped: check it
by hand.

### Demo

When a message or a step of the demo changes, record the demo again. Both commands need network
access.

- `make -C demo record` writes the text demo in Docker.
- `make -C demo all` records the GIF and the social preview in Docker. The preflight, the GIF and
  the social preview each download the linux-arm64 archives of the pair. A run without a pin file
  in `demo/app`, as after `make clean`, also pins. The container runs linux-arm64 on every host:
  an x64 host needs arm64 emulation through QEMU and binfmt, which Docker Desktop includes.

## Scope

The README states the scope in two places:
[When to use it and when not to](../README.md#when-to-use-it-and-when-not-to) and
[Scope](../README.md#scope), with a row `in` or `out` for each behavior. A feature outside the scope
is not merged.

A new cause of an error comes with a test and with an entry in the row of its exception class in
the [Exceptions](../README.md#exceptions) table of the README, usually `InstallerException`.

## Pull requests

For a bug, open a pull request with:

- a test that fails without the change, using the fake tools in `tests/FakeTools.php` where
  possible;
- the change, if you have one;
- a description of what went wrong and why the change fixes it, with the platform and the output
  of `php -v`, `curl --version` and `unzip -v`.

For a feature, open a pull request with the change, or with a test of the behavior you want.

If a coding agent drafts the change, review it, understand it and run the tests before you open
the pull request.

A pull request gets reviewed once it is ready for review and `composer test` passes in CI, or
fails only in the new test of a bug report. Inactive drafts may be closed. Pull requests opened in
bulk are closed without review.

## When the Chrome for Testing check fails

The workflow `chrome-for-testing` runs each Monday, on demand, and on a pull request or a push to
`main` that changes `.chrome-version` or the workflow. It has two jobs, and each job fails
independently.

- **Red in `Chrome for Testing · SHA-256`:** the job runs `pin` for the version of
  `.chrome-version` again, into a pin file of its own. It fails when `pin` fails or a SHA-256
  differs from `.chrome-version`; the log names the binary, the platform and both values. After a
  network error or a 5xx status, run it again. A SHA-256 that differs does not go away when you run
  the job again: the archive of a published version should not change. Do not pin the new bytes to
  make the check green. Find out why, and report it privately if it could be an attack (see
  [SECURITY.md](SECURITY.md)).
- **Red in `Chrome for Testing · Stable`:** the current Stable in
  `last-known-good-versions.json` is newer than `.chrome-version`; the log names both versions.
  The job also fails when it cannot read the list or the list names no valid Stable; then run it
  again.

To update the pin file to a new Stable:

1. Run `php bin/cdp-browser pin NEW_VERSION`.
2. Review the version and each SHA-256 in the diff of `.chrome-version`.
3. Change the files of the demo that name the old version: `grep -rlF OLD_VERSION demo`. The
   tests and `works-with/` use a version only as a sample and keep it.
4. Record the demo again, as it shows the version (see [Demo](#demo)).
5. Open a pull request. It runs both jobs on the new pin file.
