# cdp-browser-installer

**Headless Chrome™ and ChromeDriver of one pinned version for PHP browser tests, installed by Composer. No Node, no npm.**

[![Tests](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/tests.yml)
[![Chrome for Testing](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/chrome-for-testing.yml/badge.svg?branch=main)](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/chrome-for-testing.yml)
[![Works with](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/works-with.yml/badge.svg?branch=main)](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/works-with.yml)
[![Latest Version](https://img.shields.io/packagist/v/sorge-it/cdp-browser-installer)](https://packagist.org/packages/sorge-it/cdp-browser-installer)
[![License](https://img.shields.io/packagist/l/sorge-it/cdp-browser-installer)](https://github.com/sorge-it/cdp-browser-installer/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/dependency-v/sorge-it/cdp-browser-installer/php)](https://github.com/sorge-it/cdp-browser-installer/blob/main/composer.json)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](https://github.com/sorge-it/cdp-browser-installer/blob/main/phpstan.neon)

`sorge-it/cdp-browser-installer` is a Composer dev dependency. It installs `chrome-headless-shell`
and `chromedriver` of one [Chrome for Testing](https://googlechromelabs.github.io/chrome-for-testing/)
version. This README calls the two binaries the pair. It is the PHP counterpart of
`npx @puppeteer/browsers install`.

- **One version for both.** The browser and ChromeDriver come from one pin, so ChromeDriver does
  not stop with `session not created: This version of ChromeDriver only supports Chrome version …`.
- **Checked before unpacked.** A pin file next to `composer.lock` holds the version and the
  SHA-256 of each archive. `install` checks the SHA-256 of each archive before the unpack, also
  for an archive from a CI cache.
- **The same pair everywhere.** Each checkout, container and CI run reads the same pin file, so a
  test does not fail in CI only because the browser or ChromeDriver differs.
- **No Node.** `curl` and `unzip` do the work; PHP decides and checks.
- **Linux and macOS, on x64 and arm64.** On Alpine and other musl systems, set
  `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER` to the system's `chromium` and
  `chromedriver` (see [Alpine](#alpine-and-other-musl-systems)). Composer refuses the package on
  Windows.

**Built for coding agents and CI.** An agent writes the browser test, and CI installs the pair on
each run. You review the pin file once, in the pull request, like a change to `composer.lock`. A
command that stops names the cause and the next step. It ends with exit code 75 (a retry can
help), 65 (wrong SHA-256 or MD5) or 1 (any other cause).

![cdp-browser install checks the SHA-256 of the chrome-headless-shell and chromedriver archives, installs both, then refuses an archive with one byte added](https://github.com/sorge-it/cdp-browser-installer/raw/main/demo/output/demo.gif)

The demo installs the pair from the pin file. Then it removes the pair, keeps the archives as a CI
cache does, and adds one byte to the archive of the browser: `install` deletes that archive and
unpacks nothing. The demo runs in Docker on Debian 13, linux-arm64, and the GIF skips the wait
for the download.

## Quick start

```sh
composer require --dev sorge-it/cdp-browser-installer
vendor/bin/cdp-browser pin 157.0.8103.38               # writes the pin file .chrome-version; commit it
vendor/bin/cdp-browser install                         # in each checkout, container and CI run
vendor/bin/cdp-browser path chrome-headless-shell      # prints the path of the browser
vendor/bin/cdp-browser path chromedriver               # prints the path of ChromeDriver
```

- Take the version from the
  [Chrome for Testing dashboard](https://googlechromelabs.github.io/chrome-for-testing/). `pin`
  downloads the archives of both binaries for each platform and writes the version and each
  SHA-256.
- If `curl`, `unzip` or a library of the pair is missing, run `vendor/bin/cdp-browser deps` as
  root first. `install` and `pin` stop as root without `--allow-root` (see [Commands](#commands)).
- In the
  [text demo](https://github.com/sorge-it/cdp-browser-installer/blob/main/demo/output/demo.txt)
  on macOS arm64, the first `install` took 13.2 s with the download of the pair, the second 0.0 s.
- To upgrade from release 1.x, do each step of
  [Upgrade from 1.x](https://github.com/sorge-it/cdp-browser-installer/blob/main/CHANGELOG.md#upgrade-from-1x).

Give the paths to your test tool: a WebDriver tool needs both, a CDP tool only the browser.
[Works with](#works-with) shows five tools, and a CI job runs each of them with the pair of the pin
file. The package contains no Pest or PHPUnit plugin, and it starts neither binary for a test.

**Trust.** [Security](#security) lists seven rules, each with the code that implements it.
[Limits of the security model](#limits-of-the-security-model) lists what the installer does not
check. `composer test` requires PHPStan level max and 100 % type coverage, and CI requires 100 %
line coverage. Each week, a workflow checks the SHA-256 values in this repository's pin file
against Chrome for Testing.

## Works with

Run `vendor/bin/cdp-browser install` before the examples.

- WebDriver: [`laravel/dusk`](#laraveldusk), [`symfony/panther`](#symfonypanther) and
  [`php-webdriver/webdriver`](#php-webdriverwebdriver). The tool starts ChromeDriver from
  `$binaries->path(Binary::Chromedriver)` and gives it the browser from
  `$binaries->path(Binary::ChromeHeadlessShell)`. ChromeDriver starts the browser.
- CDP: [`chrome-php/chrome`](#chrome-phpchrome) and
  [`dmore/chrome-mink-driver`](#dmorechrome-mink-driver). The tool starts the browser from
  `$binaries->path(Binary::ChromeHeadlessShell)` or connects to it.

A WebDriver tool needs no ChromeDriver of its own, such as the one that
`php artisan dusk:chrome-driver` or `vendor/bin/bdi detect drivers` installs. On Linux, each
example turns off the sandbox in its code or names `--no-sandbox` in its text, as the job does (see
[Run the browser and ChromeDriver](#run-the-browser-and-chromedriver)). `chrome-headless-shell`
has no window, so `DUSK_HEADLESS_DISABLED` and `PANTHER_NO_HEADLESS` do not open one; for a
window, use a Chrome of the system and a ChromeDriver of its version.

### laravel/dusk

[`laravel/dusk`](https://packagist.org/packages/laravel/dusk) starts ChromeDriver in `prepare()`
of `tests/DuskTestCase.php` and connects to it in `driver()`. Write that file with the class below,
put each test in `tests/Browser/`, and create `tests/Browser/console`, `tests/Browser/screenshots`
and `tests/Browser/source`, where Dusk writes. Do not run `php artisan dusk:install`: it writes
the same file, and it installs a ChromeDriver of its own. After an earlier `dusk:install`, replace
`prepare()` and `driver()` and add `binaries()`.

`visit()` loads a path below `APP_URL`, so a server must serve the app. Set `APP_URL` to its
address, such as `http://127.0.0.1:8000` for `php artisan serve`. Dusk stops the browser and
ChromeDriver after the tests of each class. Dusk needs `ext-zip`.

```php
namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

abstract class DuskTestCase extends BaseTestCase
{
    #[BeforeClass]
    final public static function prepare(): void
    {
        static::useChromedriver(self::binaries()->path(Binary::Chromedriver));
        static::startChromeDriver(['--port=9515']);
    }

    protected function driver(): RemoteWebDriver
    {
        $options = new ChromeOptions()->setBinary(self::binaries()->path(Binary::ChromeHeadlessShell));
        $options->addArguments(PHP_OS_FAMILY === 'Linux' ? ['--no-sandbox'] : []);

        return RemoteWebDriver::create('http://localhost:9515', DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options));
    }

    private static function binaries(): Binaries
    {
        return Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current());
    }
}
```

### symfony/panther

[`symfony/panther`](https://packagist.org/packages/symfony/panther) starts ChromeDriver from the
path that you pass to `Client::createChromeClient()`. It reads the path of the browser from
`$_SERVER['PANTHER_CHROME_BINARY']` and the sandbox setting from `$_SERVER['PANTHER_NO_SANDBOX']`.
Set both before `Client::createChromeClient()`: Panther reads them when it creates the client.
`PantherTestCase::createPantherClient()` passes no path of ChromeDriver; it looks for
`chromedriver` in `PATH`, `./drivers` and `./vendor/bin`. For the pinned ChromeDriver, create the
client as below. Panther passes `--port` to ChromeDriver itself. Panther needs `ext-dom` and
`ext-libxml`, and through `php-webdriver/webdriver` also `ext-curl` and `ext-zip`.

```php
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Panther\Client;

$binaries = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current());
$_SERVER['PANTHER_CHROME_BINARY'] = $binaries->path(Binary::ChromeHeadlessShell);
$_SERVER['PANTHER_NO_SANDBOX'] = PHP_OS_FAMILY === 'Linux' ? '1' : '0';
$client = Client::createChromeClient($binaries->path(Binary::Chromedriver));

try {
    // Drive the page.
} finally {
    $client->quit();
}
```

### php-webdriver/webdriver

[`php-webdriver/webdriver`](https://packagist.org/packages/php-webdriver/webdriver) starts
ChromeDriver from the path of the pair, and ChromeDriver starts the browser from the path in
`ChromeOptions`. Give `ChromeDriverService` the port twice: as its second argument, and as
`--port` in its arguments. The service passes only the arguments to ChromeDriver, and without
`--port`, ChromeDriver 157 listens on a random port. `quit()` stops the browser and ChromeDriver.
It needs `ext-curl` and `ext-zip`.

```php
use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

$binaries = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current());
$options = new ChromeOptions()->setBinary($binaries->path(Binary::ChromeHeadlessShell));
$options->addArguments(PHP_OS_FAMILY === 'Linux' ? ['--no-sandbox'] : []);
$capabilities = DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options);
$service = new ChromeDriverService($binaries->path(Binary::Chromedriver), 9515, ['--port=9515']);
$driver = ChromeDriver::start($capabilities, $service);

try {
    // Drive the page.
} finally {
    $driver->quit();
}
```

### chrome-php/chrome

[`chrome-php/chrome`](https://packagist.org/packages/chrome-php/chrome) starts the browser from
the path of `Binary::ChromeHeadlessShell` in the pair that `binaries()` returns. It needs
`ext-sockets`.

```php
use HeadlessChromium\BrowserFactory;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

$binary = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current())->path(Binary::ChromeHeadlessShell);
$browser = new BrowserFactory($binary)->createBrowser(['noSandbox' => PHP_OS_FAMILY === 'Linux']);

try {
    $page = $browser->createPage();
    // Drive the page.
} finally {
    $browser->close();
}
```

### dmore/chrome-mink-driver

[`dmore/chrome-mink-driver`](https://packagist.org/packages/dmore/chrome-mink-driver) connects to
a browser that already runs; `dmore/behat-chrome-extension` uses this driver. The driver needs
`ext-curl` and `ext-mbstring`. Start the browser with a debugging port and a profile directory.
On Linux, add `--no-sandbox`. The browser writes the port to `DevToolsActivePort` in the profile
once it listens; wait for that file before the driver connects. Remove `.chrome-profile` before
each run: an old `DevToolsActivePort` in it names the port of an earlier browser. Stop the browser
after the tests.

```sh
"$(vendor/bin/cdp-browser path chrome-headless-shell)" --headless --remote-debugging-port=9222 --user-data-dir="$PWD/.chrome-profile" &
```

```php
use Behat\Mink\Session;
use DMore\ChromeDriver\ChromeDriver;

$session = new Session(new ChromeDriver('http://127.0.0.1:9222', null, 'http://localhost'));
$session->start();

try {
    // Drive the page.
} finally {
    $session->stop();
}
```

### The CI job `works-with`

The CI job [`works-with`](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/works-with.yml)
runs each tool in this section with the pair of the pin file of this repository. It installs the
newest release of each tool, loads a page through it and reads the text of one element. It also
checks that the browser, and for a WebDriver tool also ChromeDriver, reports the pinned version.
For Laravel Dusk, `php artisan serve` serves the page from a new Laravel app. The job runs with
PHP 8.5 on Linux x64, Linux arm64 and macOS arm64. It runs each Monday, on demand, after each
merge, and on each pull request that changes the pin file, the installer or the job.

Each tool has a step with its name, so the failed step names the tool. The job also fails when a
tool is not at its newest release, when `composer audit` finds an advisory, when `deps` or
`install` stops, or when the Laravel app for Dusk cannot be created. When a new release of a tool
fails, require the version of the last green run in your project.

A test of `composer test` keeps three parts of each example equal to the scripts in
[`works-with/`](https://github.com/sorge-it/cdp-browser-installer/tree/main/works-with): the PHP
lines that set up the tool, the `finally` block that stops it, and the shell command of
`dmore/chrome-mink-driver`. For `laravel/dusk`, it compares the methods of `DuskTestCase`.

## Run the browser and ChromeDriver

`vendor/bin/cdp-browser path chrome-headless-shell` prints the path of the browser, and
`vendor/bin/cdp-browser path chromedriver` the path of ChromeDriver. Start each from that path, not
through a symbolic link: on macOS, Chrome looks for its data files next to the path it was started
from. Both report the version of the pin file:

```sh
"$(vendor/bin/cdp-browser path chrome-headless-shell)" --version   # Google Chrome for Testing 157.0.8103.38
"$(vendor/bin/cdp-browser path chromedriver)" --version            # ChromeDriver 157.0.8103.38 (…)
"$(vendor/bin/cdp-browser path chrome-headless-shell)" --dump-dom 'data:text/html,<p>ok</p>'
```

A WebDriver tool that connects to a running ChromeDriver needs it started with a port, such as
`"$(vendor/bin/cdp-browser path chromedriver)" --port=9515`. Without the path of the browser as
`binary` in `goog:chromeOptions`, ChromeDriver looks for a Chrome of the system.

On Ubuntu 23.10 and later, AppArmor blocks the user namespaces of Chrome's sandbox. Most
containers have no user namespace for it. Chrome then stops with `No usable sandbox!`. Start it
with `--no-sandbox` there; the installer does not decide that for you.

## The pin file

```json
{
    "version": "157.0.8103.38",
    "chrome-headless-shell": {
        "linux64": "deafffa74660b34853a4119c361fd427ac8c9fcb62db51ddb8a797cd83a4ba2d",
        "linux-arm64": "b9b910a57b2a15eb3e55000a2339f93a973934a413ebfc6e585a50ec6c42fd6c",
        "mac-x64": "79821745d0a72d2ba752d981284ed0a9e6fa320dad133596941a17dba6186de3",
        "mac-arm64": "e6a6b13551aa12f951d0891d79282606b13742837c8fa0505e1272bae116ce82"
    },
    "chromedriver": {
        "linux64": "329525fa0bf02be19794de1e9475772ef64695e0456f142888bd70df3147dc1e",
        "linux-arm64": "8166fad77893cb095a45678f29ebec7ea14a8ac89364d28d52176a6fd4b30262",
        "mac-x64": "7725504324ce8e8e03f78392ecf288890e082d215f619edd87a2dc1235e780a4",
        "mac-arm64": "e59394fda6a05067362c225678e185e46e473cebea1593025deb2b2641b75688"
    }
}
```

The pin file names one version and the SHA-256 of the archive of each binary for each platform.
`install` and `path` refuse a pin file of release 1.x with exit code 1, and the message names
`cdp-browser pin <version>`, which writes a new one.

Chrome for Testing publishes no SHA-256 and no signature: its JSON endpoints list only a platform
and a URL for each download. So `pin` computes the SHA-256 from its own download (see
[Limits of the security model](#limits-of-the-security-model)). Commit the file and review each
change to it: the pin is the point where you trust Google.

The workflow [`chrome-for-testing`](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/chrome-for-testing.yml)
checks the pin file of this repository each Monday, on demand, and on each pull request that
changes the pin file or the workflow. It has two jobs:

- `Chrome for Testing · SHA-256` runs `pin` again for the pinned version. It fails when `pin`
  fails or a SHA-256 differs. When Chrome for Testing serves other bytes for the pinned version,
  each install that downloads an archive of that version stops with exit code 65. A pair that is
  installed, or a cached archive that matches the pin file, stays usable.
- `Chrome for Testing · Stable` fails when Chrome for Testing has a newer Stable than the pin, or
  when the job cannot read the list of versions.

[CONTRIBUTING.md](https://github.com/sorge-it/cdp-browser-installer/blob/main/CONTRIBUTING.md#when-the-chrome-for-testing-check-fails)
gives the next step for each job. To update the pin file of your project, run `pin`.

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
use SorgeIt\CdpBrowserInstaller\Binaries;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\DownloadException;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

// The environment decides: CDP_BROWSER_CHROME, CDP_BROWSER_CHROMEDRIVER, CDP_BROWSER_PATH,
// XDG_CACHE_HOME, HOME, CDP_BROWSER_TIMEOUT, CDP_BROWSER_LOCK_TIMEOUT.
$pair = static fn (): Binaries => Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current());

try {
    $binaries = $pair();
} catch (DownloadException) {
    // A retry can help, as after a network error or a 5xx status.
    $binaries = $pair();
}

$browser = $binaries->path(Binary::ChromeHeadlessShell);
$chromedriver = $binaries->path(Binary::Chromedriver);
```

`binaries()` checks and returns the system's pair, or returns the paths of the pinned pair and
installs it first if a binary is missing. A `ChecksumException` and every other
`InstallerException` reach the caller (see [Exceptions](#exceptions)).

Without the environment, pass each value to the constructors:

```php
use SorgeIt\CdpBrowserInstaller\CommandLine;

$installer = new Installer(new CommandLine(timeout: 300), '/srv/cache/cdp-browser', lockTimeout: 60);
$binaries = $installer->install(Pin::read('.chrome-version'), Platform::current());
```

[API](#api) lists the classes and members that SemVer covers.

## Comparison with Puppeteer, Playwright, Laravel Dusk, Symfony Panther and bdi

Chrome for Testing's README names
[`@puppeteer/browsers`](https://github.com/GoogleChromeLabs/chrome-for-testing/blob/f0abac9d5b65cc206b06d1707ecda7a57f094069/README.md#L152-L154)
as "the easiest way" to download it. That package, Puppeteer and Playwright run on Node. The PHP
packages for browser tests install a driver, use a browser that is already installed, or rely on
Playwright or Puppeteer from npm. As of 2026-11-02, from the source code of each version:

| Package | Version | Made for | Installs | Node | Pins a version | Checks a SHA-256 | Locks a parallel install | Windows |
|---|---|---|---|---|---|---|---|---|
| [**`sorge-it/cdp-browser-installer`**](https://github.com/sorge-it/cdp-browser-installer) | this README | installing a pinned, SHA-256-checked `chrome-headless-shell` and `chromedriver` as a Composer dev dependency | `chrome-headless-shell` and `chromedriver` of one version from Chrome for Testing, for Linux and macOS on x64 and arm64 | no | yes, in the pin file | yes, before each unpack | yes, for each version and platform | no: Composer refuses it, as it requires `ext-posix` |
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
- Linux with glibc, or macOS, on x64 or arm64. On musl, set `CDP_BROWSER_CHROME` and
  `CDP_BROWSER_CHROMEDRIVER` to the system's pair (see [Alpine](#alpine-and-other-musl-systems)).
  Composer refuses the package on Windows, where PHP has no `ext-posix`. On other platforms, the
  installer stops with an `InstallerException`.

Ubuntu 24.04 ships PHP 8.3; there, install PHP 8.4, for example from `ppa:ondrej/php`.

---

The rest of this file is reference:
[How it works](#how-it-works) · [Security](#security) ·
[Limits of the security model](#limits-of-the-security-model) · [Commands](#commands) ·
[Environment variables](#environment-variables) · [Where the pair goes](#where-the-pair-goes) ·
[Alpine](#alpine-and-other-musl-systems) · [API](#api) · [Exceptions](#exceptions) ·
[Tests](#tests) · [Development](#development)

## How it works

Native tools do the work; PHP decides and checks. `curl` downloads, `zipinfo` lists and `unzip`
unpacks, each from an argument list in
[`Download`](https://github.com/sorge-it/cdp-browser-installer/blob/main/src/Download.php) and
[`sorge-it/safe-unzip`](https://github.com/sorge-it/safe-unzip). Composer unpacks the same way:
its `ZipDownloader` tries `unzip` first, because `ZipArchive` loses the Unix modes. A browser
without its executable bit does not start.

Composer trusts `unzip`. This installer adds checks before and after it:

| Step | Native tool | PHP checks |
|---|---|---|
| Download | `curl -q --proto =https --tlsv1.2 --max-filesize …`, without `--location` | the status is 200; a failed download leaves no archive; `pin` compares the MD5 from `x-goog-hash` |
| Archive | — | the SHA-256 of the pin file, before each unpack |
| Before unpacking | `zipinfo -h`, `zipinfo -v` | each line against a closed grammar, then safe-unzip's "standard ZIP" profile: methods, hosts, flags, extra fields, names, types, counts, offsets, and fixed header fields read at the offsets of the listing |
| Check pass | `unzip -p` | each entry decompresses into a pipe and has the size and the CRC-32 of the listing; nothing is written |
| Unpacking | `unzip -q` | the written tree holds exactly the files of the listing, with their sizes and CRC-32, and no link; safe-unzip sets each mode without write access for group and others |
| Into place | `rm -rf` of the temporary directories | a lock per version and platform, for each binary the old tree aside and the new one in, one rename each; the stamp of the pair after both |

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

1. **Pinned.** One version, one SHA-256 for each binary and each platform, in a file in your
   repository (`Pin`).
   `pin` first compares each download with the MD5 in `x-goog-hash` (`PinCommand`).
2. **One source.** Downloads come only from `https://storage.googleapis.com/chrome-for-testing-public/`
   (`Download::SOURCE`). `curl -q` reads no `.curlrc`. `--proto =https --tlsv1.2` allows only
   HTTPS with TLS 1.2 or later, and TLS verification stays on. `--max-filesize` stops at 500 MiB.
   Any status other than 200 stops the install; without `--location`, this includes a redirect.
   A failed download, also one stopped by a timeout, leaves no archive: `install` downloads into a
   `.part-*` file of the run and deletes it. If it cannot delete the file, as in a directory that
   turned read-only, the file stays. The next install that unpacks removes it once its last write
   is 120 seconds old.
   `pin` removes its own directory with each download in it.
3. **Checked before unpacked.** A SHA-256 that differs from the pin file stops the install and
   deletes the archive (`Installer::verify`). The check runs before each unpack: on a new
   download, and on an archive that a CI cache restored. For each binary in turn, the install
   downloads the archive into a `.part-*` file of its own run, or renames the cached archive to
   one. It checks and unpacks that file, then moves it into the cache. A pair whose two binaries
   and stamp match the pin file counts as installed, and its archives are not read again.
4. **No entry outside the target.** Before `unzip` writes a byte,
   [safe-unzip](https://github.com/sorge-it/safe-unzip) parses `zipinfo -v` with a closed
   grammar, checks the archive against its "standard ZIP" profile, and decompresses each entry
   into a pipe to compare its size and CRC-32 with the listing, so no zip bomb reaches the disk.
   It refuses, among others, links and devices, encrypted entries, a name outside
   `A-Z a-z 0-9 . _ - /` or with a `.` or `..` segment, two entries with the same name, more than
   10,000 entries and more than 2 GiB unpacked. After `unzip`, the directory must hold exactly
   the files of the listing, with their sizes and CRC-32, and no link. Every tool runs with
   `LC_ALL=C`, and without `UNZIP`,
   `UNZIPOPT`, `ZIPINFO` and `ZIPINFOOPT`, which would add options from the environment.
5. **One install at a time.** A lock file per version and platform makes a second install wait
   for the first one, and then find the pair installed. The stamp `.sha256-pair` holds the SHA-256
   of each archive. `install` unpacks both binaries again when a binary or the stamp is missing,
   or when the stamp names another archive. It removes the stamp before it moves the first tree,
   and writes it when both trees are in place, so a pair with only one new binary counts as not
   installed. Each new tree moves in with one rename, and the old tree is removed (`Installer`).
   A binary that still runs from the old tree keeps only the files it has open. An install that
   finds the pair installed takes no lock; the next run that unpacks removes the files of a
   stopped run. For a shared file system, see
   [Limits of the security model](#limits-of-the-security-model).
6. **Root only for the package manager.** Only `deps` needs root. It installs a fixed package
   list from the system's signed sources (`SystemLibraries`). It finds `apk` or `apt-get` in
   `PATH`, passes the caller's environment plus `DEBIAN_FRONTEND=noninteractive`, and runs it
   without a timeout, so that no timeout leaves dpkg half configured; Ctrl-C or SIGKILL still can.
   The commands `install` and `pin` stop as root without `--allow-root` (`Cli::refuseRoot`);
   `path`, and `install` with the system's pair, write nothing; `path` and the PHP API do not
   check the user.
7. **No shell.** Every process starts from an argument list (`CommandLine`). The installer looks
   a tool up in the `PATH` that the process gets, where a relative or empty entry counts from the
   working directory; without a `PATH`, it looks in
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
  exclude a second process (measured on 2026-10-30). There, of two installs that run at the same
  time, one can end with exit code 1; run it again. NFS and bind mounts on a Linux host are not
  measured.
- **No later check of an installed pair.** The SHA-256 covers each archive before the unpack, not
  the files after it. A changed file of an installed pair stays undetected while its binaries and
  stamp match the pin file. To check it again, remove `<cache>/<version>/` and run `install`.
- **Rosetta.** `Platform::current()` reads `php_uname('m')`. An x64 build of PHP that runs through
  Rosetta 2 on Apple silicon reports `x86_64` and installs `mac-x64`, which then also runs through
  Rosetta. Not measured; use an arm64 build of PHP.
- **Older distributions.** `deps` uses the package names from after the 64-bit time transition,
  such as `libasound2t64`. They exist on Debian 13 and on Ubuntu 24.04 and later; CI checks the
  list on minimal images of Debian 13 and Ubuntu 26.04. On an older release, apt stops at the
  first unknown name, and so does `deps`. There, install the libraries that
  `ldd chrome-headless-shell chromedriver` lists yourself.
- **Two timeouts.** The lock timeout (600 seconds) covers the whole install of the other run: both
  downloads and each run of `zipinfo` and `unzip`, each of which can take up to the tool timeout
  (900 seconds). On a slow connection, a second install can stop at the lock while the first one
  is still running. There, set `CDP_BROWSER_LOCK_TIMEOUT` above the duration of one install.
- **Old versions stay.** A new pin installs into a new directory `<cache>/<version>/`. No command
  removes the directory of an old version; remove it yourself.

## Commands

| Command | Does | Runs as |
|---|---|---|
| `pin <version>` | Checks that it can write the pin file and that the real path of its temporary directory has no refused character, then downloads the archive of each binary for each platform, compares its MD5 with the one the storage sends, unpacks it once with the checks of `install`, and writes the version and each SHA-256 to the pin file | a normal user; root with `--allow-root` |
| `install` | Installs the pinned pair for this platform, or checks the system's pair in `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER`, and prints both paths in its message; a script reads a path from `path` | a normal user; root with `--allow-root`, or with the system's pair |
| `path <binary>` | Prints the path of `chrome-headless-shell` or `chromedriver`; without a name it stops and names both; it writes nothing | any user, including root |
| `deps` | Installs `curl`, `unzip` and the libraries of the pair through apt, or Alpine's `chromium` and `chromium-chromedriver` through apk; on macOS it does nothing. For the systems, see [Older distributions](#limits-of-the-security-model) | root |

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
| 1 | `InstallerException` | stop: a human fixes the cause in the message. When another install removed a file of this one, run `install` again |

Any other exception, such as a bug, is shown in Symfony's format and ends with exit code 1.
`install` prints `Download <url>` before each download, as `pin` does.

`install` and `pin` stop when they run as root: a pair or a pin file owned by root breaks the
next run as a normal user, who cannot replace it. In a container where only root runs, as in a
Dockerfile `RUN`, `--allow-root` accepts that risk. A later run as another user with the same
`CDP_BROWSER_PATH` finds the pair installed. No environment variable sets the option; add it to
the command line:

```dockerfile
RUN vendor/bin/cdp-browser deps && vendor/bin/cdp-browser install --allow-root
```

## Environment variables

| Variable | Default | Does |
|---|---|---|
| `CDP_BROWSER_CHROME` | none | an absolute path to the system's browser, such as `/usr/bin/chromium`. Only together with `CDP_BROWSER_CHROMEDRIVER`: with both, `install` and `path` read no pin file, download nothing and need no cache |
| `CDP_BROWSER_CHROMEDRIVER` | none | an absolute path to the system's ChromeDriver, such as `/usr/bin/chromedriver`. Only together with `CDP_BROWSER_CHROME` |
| `CDP_BROWSER_PATH` | `$XDG_CACHE_HOME/cdp-browser` if `XDG_CACHE_HOME` is absolute, else `~/.cache/cdp-browser` | the cache directory (see below) |
| `CDP_BROWSER_TIMEOUT` | `900` | the timeout in seconds for each tool that `install`, `path` and `pin` start: one `curl` download, each run of `zipinfo` and `unzip`, and `--version` of each binary of the system's pair. `deps` has no timeout |
| `CDP_BROWSER_LOCK_TIMEOUT` | `600` | how many seconds an install waits for another install in the same version directory, `<cache>/<version>/<platform>/`. `install` and `path` read it together with the cache; with the system's pair, and in `pin` and `deps`, it is not read |

A browser of the system with a pinned ChromeDriver, or the reverse, can differ in version. So when
only one of `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER` is set, `install` and `path` stop
with exit code 1, `Installer::fromEnvironment()` throws an `InstallerException`, and the message
names the other. While `CDP_BROWSER_BINARY` of release 1.x is set and not empty, they also stop,
and the message names both variables. `pin` and `deps` read none of the three.

Both timeouts take a whole number of seconds above 0, in digits only, without a sign, a leading
zero or a space: `900`, not `15m`, `1e3`, `+900`, ` 900` or `0900`. `0` is refused: it would mean
no limit for a tool but no wait at the lock. Any other value stops each command that reads the
variable with an `InstallerException`. An empty variable counts as not set. `curl` also stops on
its own when the speed stays below 1 KiB/s for 60 seconds.

## Where the pair goes

`install` puts the pair under `<cache>/<version>/<platform>/`: `chrome-headless-shell-<platform>/`
and `chromedriver-<platform>/` side by side, each next to the archive it came from, and the
stamp `.sha256-pair` with the SHA-256 of both archives. The cache directory is:

1. `CDP_BROWSER_PATH`, if set (a relative path is resolved against the working directory),
2. else `$XDG_CACHE_HOME/cdp-browser`, if `XDG_CACHE_HOME` is absolute; as the XDG Base
   Directory Specification asks, a relative value, also one that starts with `~`, is ignored,
3. else `~/.cache/cdp-browser`.

`.` and `..` are resolved textually, without following symbolic links. The install stops at the
root directory as the cache, and at a `CDP_BROWSER_PATH` or `HOME` that starts with `~`: only a
shell expands `~`, not a `.env` file or a Dockerfile. It also stops before the download at a cache
path with `*`, `?`, `[`, `]`, `\` or a line break, also where a symbolic link in the path leads to
such a name. `unzip` reads the first five as a pattern, and `zipinfo` prints a line break as two
lines, so safe-unzip would refuse the real path of the archive. `pin` refuses these
characters in the real path of its temporary directory. The constructor of `Installer` checks its
cache directory the same way, and takes no relative path. With the system's pair, the installer
needs no cache directory; a relative path in `CDP_BROWSER_CHROME` or `CDP_BROWSER_CHROMEDRIVER`
stops the command.

In a container, set `CDP_BROWSER_PATH` to a directory in the project, so that the next container
finds the pair of the previous one. To start the browser, see
[Run the browser and ChromeDriver](#run-the-browser-and-chromedriver).

## Alpine and other musl systems

Chrome for Testing is built against glibc and does not start on musl, not even with `gcompat`. On
Alpine 3.24.3, aarch64, `chrome-headless-shell` 155 stopped with exit code 127 and "unsupported
relocation type 1032" (measured on 2026-10-31). Playwright does not support Alpine; Puppeteer,
Symfony Panther, chrome-php and Browsershot use Alpine's `chromium` and an environment variable
with its path. This installer does the same, for the pair:

```sh
vendor/bin/cdp-browser deps   # as root: apk add --no-cache chromium chromium-chromedriver nss freetype harfbuzz ttf-freefont
CDP_BROWSER_CHROME=/usr/bin/chromium CDP_BROWSER_CHROMEDRIVER=/usr/bin/chromedriver vendor/bin/cdp-browser install
```

Without the two variables, `install` and `path` detect musl (`getconf`, then the loader in the
memory maps of the PHP process, then the loader file) and stop before any download; the message
names `deps` and both variables with their paths. On a musl system without apk, `deps` stops;
install Chromium and its ChromeDriver yourself and set both variables to their paths.

The version of the pair is Alpine's, not the pin file's. On 2026-11-03, Alpine 3.24 shipped
`chromium` and `chromium-chromedriver` 156.0.8078.79 from one build. `path chrome-headless-shell`
prints the path in `CDP_BROWSER_CHROME`, such as `/usr/bin/chromium`. It is the full Chromium, so
without a display, start it with `--headless`. In the examples for `php-webdriver/webdriver` and
`laravel/dusk`, add `--headless` to the arguments of `ChromeOptions`. Without it, ChromeDriver
stops with `session not created: Chrome instance exited`. Panther adds `--headless` itself. This
was measured by hand on 2026-11-03 with `php-webdriver/webdriver` and `symfony/panther`; the job
`works-with` does not run on Alpine. To check the browser:

```sh
/usr/bin/chromium --headless --no-sandbox --dump-dom 'data:text/html,<p>ok</p>'
```

## API

These classes and members are the API; SemVer covers them. Only a new major release breaks them,
and the CHANGELOG marks each break as **Breaking**; Composer's `^2.0` takes no such release:

```php
namespace SorgeIt\CdpBrowserInstaller;

final readonly class Installer
{
    public const string OWN_CHROME = 'CDP_BROWSER_CHROME';
    public const string OWN_CHROMEDRIVER = 'CDP_BROWSER_CHROMEDRIVER';
    public const string TIMEOUT = 'CDP_BROWSER_TIMEOUT';
    public const string LOCK_TIMEOUT = 'CDP_BROWSER_LOCK_TIMEOUT';

    public function __construct(CommandLine $commandLine, ?string $cache, ?Binaries $own = null, ?bool $musl = null, int $lockTimeout = 600);
    /** @param array<string, string> $environment */
    public static function fromEnvironment(array $environment, CommandLine $commandLine = new CommandLine, ?bool $musl = null): self;
    /** @param array<string, string> $environment */
    public static function cache(array $environment): string;
    /** @param (\Closure(string): void)|null $onDownload gets the URL before each download */
    public function binaries(string $pinFile, Platform $platform, ?\Closure $onDownload = null): Binaries;
    /** @param (\Closure(string): void)|null $onDownload gets the URL before each download */
    public function install(Pin $pin, Platform $platform, ?\Closure $onDownload = null): Binaries;
    public function paths(Pin $pin, Platform $platform): Binaries;
    public function isInstalled(Pin $pin, Platform $platform): bool;
    public function ownBinaries(): ?Binaries;
}

final readonly class Binaries
{
    public function __construct(string $chromeHeadlessShell, string $chromedriver);
    public function path(Binary $binary): string;
}

enum Binary: string
{
    case ChromeHeadlessShell = 'chrome-headless-shell';
    case Chromedriver = 'chromedriver';

    public function variable(): string;
    public function alpinePath(): string;
    public function directory(Platform $platform): string;
    public function path(Platform $platform): string;
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

    /** @param array<string, array<string, string>> $hashes the SHA-256 for each value of Binary and each value of Platform */
    public function __construct(string $version, array $hashes);
    public static function checkVersion(string $version): void;
    public static function read(string $file): self;
    public function write(string $file): void;
    public function hash(Binary $binary, Platform $platform): string;
}

enum Platform: string
{
    case LinuxX64 = 'linux64';
    case LinuxArm64 = 'linux-arm64';
    case MacX64 = 'mac-x64';
    case MacArm64 = 'mac-arm64';

    public static function detect(string $family, string $machine): self;
    public static function current(): self;
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
the package manager, each binary of the system's pair, and `zipinfo` and `unzip` through
safe-unzip.

The rest is `@internal` and can change in any release: the command classes of `bin/cdp-browser`
(`Console\*`), the steps of an install (`Download`, `Unpack`, `Libc`, `SystemLibraries`,
`Environment`, `Files`), every method of `CommandLine`, `Installer::onMusl()`, and the
constructors of the three exceptions. The API covers what a test tool calls: find, install and
check the pair, and tell a failed download from a wrong SHA-256. `CommandLine` is part of the API
only for its constructor, the one place where a caller sets the environment and the timeout of the
tools.

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
| `ChecksumException` | the SHA-256 of an archive differs from the pin file; that archive is deleted and not unpacked, and the pair counts as not installed. In `pin`: the MD5 of a download differs from the one in `x-goog-hash`; no pin file is written | after a new download, a retry does not help: the bytes are not the pinned ones. If the run deleted an archive from the cache, a retry downloads it again. The message names what happened to the archive. In `pin`, a retry downloads again; the message names the binary, the platform and both MD5 values |
| `InstallerException` | every other cause: a value of the environment, an option or an argument; the user; the pin file, also one of release 1.x; a version that the source does not have for the platform (status 404), such as a wrong version for `pin`; the platform or musl; `CDP_BROWSER_CHROME` or `CDP_BROWSER_CHROMEDRIVER`, also only one of them; `CDP_BROWSER_BINARY` of release 1.x; a missing tool, also `curl`; a tool other than `curl` that stops; safe-unzip refuses the archive or finds it broken, or the archive holds no binary of the platform; the file system; the lock of another install; `path` without the name of a binary, or before `install` | a human fixes the cause that the message names |

safe-unzip reads an archive only after its SHA-256 matched the pin file; in `pin`, the SHA-256
comes from the same bytes. So a refusal by safe-unzip is an `InstallerException`, not a
`ChecksumException`. Its previous exception, safe-unzip's `RefusedException`, names the rule in
`$reason`.

A failed removal in a `finally` does not replace the first error: the first error names the
cause, and the next install removes what the run left.

PHP's `TypeError` and `ValueError` are not exceptions of the package: they mark a call that
violates the signature, such as a path with a NUL byte. The API suppresses the warning of a failed
file-system call and throws its own exception, also under an error handler that turns warnings
into an `ErrorException`, as Laravel's does.

## Tests

```sh
composer test           # pint, rector, PHPStan level max, type coverage 100 %, every suite
composer test:coverage  # line coverage 100 %, with pcov; a CI job
```

The suite runs on Symfony 8, as Pest 5 requires it. `composer.json` also allows Symfony 7.4.8 or
later, and the CI job `symfony-7` runs the commands with the lowest allowed versions of the
dependencies, Symfony 7.4.8, without the development packages.

## Development

Report a bug or request a feature with a pull request, not an issue; a pull request with a
failing test is enough. How to run the checks and record the demo:
[CONTRIBUTING.md](https://github.com/sorge-it/cdp-browser-installer/blob/main/CONTRIBUTING.md).
Report a vulnerability privately through GitHub; see
[SECURITY.md](https://github.com/sorge-it/cdp-browser-installer/blob/main/SECURITY.md).

## License

MIT, see [LICENSE](https://github.com/sorge-it/cdp-browser-installer/blob/main/LICENSE). Chrome for Testing has its own licenses; the archive of
`chrome-headless-shell` contains `LICENSE.headless_shell`, the archive of `chromedriver` contains
`LICENSE.chromedriver`.

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

- Chrome for Testing, the browser and the ChromeDriver that it installs,
- the system's Chromium, which it uses on musl,
- the browsers in the comparison.

This project is not affiliated with, endorsed by or sponsored by Google.
