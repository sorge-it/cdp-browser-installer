# Contributing

Report a bug or request a feature with a pull request, not an issue: the repository has no issues.
A pull request with a failing test is enough, even without a fix. Vulnerabilities are the
exception: report them privately, see [SECURITY.md](SECURITY.md).

## A bug

Open a pull request with:

- a test that fails without the change, using the fake tools in `tests/FakeTools.php` where
  possible;
- the change, if you have one;
- a description of what went wrong and why the change fixes it, with the platform and the output
  of `php -v`, `curl --version` and `unzip -v`.

If a coding agent drafts the change, review it, understand it and run the tests before you open
the pull request.

## A feature

Open a pull request with the change, or with a test of the behavior you want. The package installs
one thing: a pinned `chrome-headless-shell` from Chrome for Testing, or the system's browser on
musl. Features for Windows, for another browser or a driver such as ChromeDriver, or for another
download source are out of scope and will be declined.

A new reason to stop comes with a test and with its class in the [Exceptions](README.md#exceptions)
table of the README, usually `InstallerException`.

## Before you open it

Run the two checks under [Tests](README.md#tests) in the README. `composer lint` runs rector, then
pint, and fixes what they find.

To run mutation testing by hand: `vendor/bin/pest --mutate --everything --covered-only`, without
`--parallel`. In Pest 5.3, `--parallel` counts every mutant as caught: 717 of 717 in 17 seconds,
while a serial run of the same code found 24 untested and 3 timeouts. On 1.0.0, the serial run
finds 17 untested and 3 timeouts of 731 mutants, in about 12 minutes in Docker. Pest's stream wrapper
makes the tests of `flock()` and of unreadable files fail without any mutant, so a mutant there
that counts as caught may have escaped: check it by hand.

The code must run on PHP 8.4, on Linux with glibc and musl, and on macOS. When a message or a step
of the demo changes, record the demo again. Both commands need network access: `pin` downloads the
four archives of 156.0.8078.80, 445 MiB in total.

- `make -C demo record` writes the text demo on your machine and needs PHP 8.4, Composer, curl and
  unzip.
- `make -C demo all` records the GIF and the social preview in Docker. The preflight, the GIF and
  the social preview each download the linux-arm64 archive, 126,679,292 bytes or 121 MiB. A run
  without a pin file in `demo/app`, as after `make clean`, also pins: 808 MiB in total. The
  container runs linux-arm64 on every host: an x64 host needs arm64 emulation through QEMU and
  binfmt, which Docker Desktop includes.

## When the Chrome for Testing check fails

The workflow `chrome-for-testing` runs each Monday, on demand, and on a pull request that changes
`.chrome-version` or the workflow. It has two jobs, and each job fails independently. GitHub sends
the notice of a scheduled run to the user who created the workflow or last changed its `cron`
line. Do not make either job a required check: a new Stable would block pull requests.

- **Red in `Chrome for Testing · SHA-256`:** the job runs `pin` for the version of
  `.chrome-version` again, into a pin file of its own: four downloads, 445 MiB. It fails when
  `pin` fails or a SHA-256 differs from `.chrome-version`; the log names the platform and both
  values. After a network error or a 5xx status, run it again. A SHA-256 that differs does not go
  away when you run the job again: the archive of a published version should not change. Do not
  pin the new bytes to make the check green. Find out why, and report it privately if it could be
  an attack (see [SECURITY.md](SECURITY.md)).
- **Red in `Chrome for Testing · Stable`:** the current Stable in
  `last-known-good-versions.json` is newer than `.chrome-version`; the log names both versions.
  The job also fails when it cannot read the list or the list names no valid Stable; then run it
  again.

To update the pin file to a new Stable:

1. Run `php bin/cdp-browser pin <new version>`.
2. Review the version and the four SHA-256 in the diff of `.chrome-version`.
3. Change the other files that name the old version: the README, this file and `demo/`. Find
   them with `grep -rn <old version> README.md CONTRIBUTING.md demo`. The tests and `src/` use a
   version only as a sample and keep it.
4. Record the demo again, as it shows the version (see [Before you open it](#before-you-open-it)).
5. Open a pull request. It runs both jobs on the new pin file.

In a public repository, GitHub disables a scheduled workflow when the repository has had no
activity for 60 days. A disabled workflow does not start, not even on demand. Enable it
again under Actions, or with `gh workflow enable chrome-for-testing.yml`; then it runs on demand
and on the schedule again.

## Review

A pull request gets reviewed once it is ready for review and `composer test` passes in CI. Inactive
drafts may be closed. Pull requests opened in bulk are closed without review.
