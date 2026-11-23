# cdp-browser-installer: pinned chrome-headless-shell and ChromeDriver for PHP

**Installs matching chrome-headless-shell and ChromeDriver for PHP browser tests with Laravel Dusk, Symfony Panther, php-webdriver, chrome-php or Mink, pinned to one Chrome for Testing version and SHA-256-checked, on glibc Linux and macOS. No Node.**

[![Tests](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/tests.yml)
[![Chrome for Testing: checks that the pinned archives keep their SHA-256, and that the pin is not older than the current Stable](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/chrome-for-testing.yml/badge.svg?branch=main)](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/chrome-for-testing.yml)
[![Works with: checks that Dusk, Panther, php-webdriver, chrome-php and Mink drive the pair or its browser](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/works-with.yml/badge.svg?branch=main)](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/works-with.yml)
[![Latest Version](https://img.shields.io/packagist/v/sorge-it/cdp-browser-installer)](https://packagist.org/packages/sorge-it/cdp-browser-installer)
[![PHP Version](https://img.shields.io/packagist/dependency-v/sorge-it/cdp-browser-installer/php)](https://github.com/sorge-it/cdp-browser-installer/blob/main/composer.json)
[![License](https://img.shields.io/packagist/l/sorge-it/cdp-browser-installer)](https://github.com/sorge-it/cdp-browser-installer/blob/main/LICENSE)

No more `session not created: This version of ChromeDriver only supports Chrome version …`: the
browser and ChromeDriver, here called the pair, come from one
[Chrome for Testing](https://googlechromelabs.github.io/chrome-for-testing/) version. The package is
the PHP counterpart of `npx @puppeteer/browsers install`.

![The command cdp-browser install puts chrome-headless-shell and ChromeDriver from the pin file in place, and both report the same version; then it refuses a cached archive with one byte added, and the next install downloads that archive again and installs the pair](https://github.com/sorge-it/cdp-browser-installer/raw/main/demo/output/demo.gif)

The demo installs the pair from the pin file, and both binaries report the pinned version. Then it
removes the pair, keeps the archives as a CI cache does, and adds one byte to the browser archive:
`install` deletes that archive and unpacks nothing, and the next `install` downloads it again. The
demo runs in Docker on Debian, linux-arm64; the GIF skips the wait for the download.

## Contents

- [Why pin chrome-headless-shell and ChromeDriver](#why-pin-chrome-headless-shell-and-chromedriver) · [Installation](#installation) · [Quick start](#quick-start)
- [Works with](#works-with): [Dusk](#laravel-dusk-laraveldusk) · [Panther](#symfony-panther-symfonypanther) · [PantherTestCase](#symfony-panther-with-panthertestcase) ·
  [php-webdriver](#php-webdriverwebdriver) · [chrome-php](#chrome-phpchrome) · [Mink](#mink-dmorechrome-mink-driver)
- [GitHub Actions](#github-actions): [Dusk](#laravel-dusk-on-github-actions)
- [Use from PHP](#use-from-php) · [Run the browser and ChromeDriver](#run-the-browser-and-chromedriver) · [The pin file](#the-pin-file)
- [Security](#security) · [When to use it and when not to](#when-to-use-it-and-when-not-to): [Scope](#scope) · [Comparison with other packages](#comparison-with-other-packages)
- [Reference](#reference): [Commands](#commands) · [Environment variables](#environment-variables) ·
  [Cache directory](#cache-directory) · [API](#api) · [Exceptions](#exceptions)
- [Troubleshooting](#troubleshooting) · [Compatibility](#compatibility): [Alpine](#alpine-and-other-musl-systems)
- [Support](#support) · [License](#license) · [Trademarks](#trademarks) · [About the author](#about-the-author)

## Why pin chrome-headless-shell and ChromeDriver

- **Browser and ChromeDriver always match.** Both come from one pin file that each checkout,
  container and CI run reads, on glibc Linux and macOS. A test does not fail because the two differ
  in version, or because CI has another pair.
- **Checked before unpacking.** The pin file next to `composer.lock` holds the SHA-256 for each
  archive. `install` checks each archive before unpacking it, also one from a CI cache.
- **No Node.** `curl` and `unzip` do the work; PHP decides and checks. Your app may still need
  Node to build its assets.
- **For coding agents and CI.** An agent writes the browser test, CI installs the pair on each
  run, and you review the pin file in the pull request, like a change to `composer.lock`. A failing
  command names the cause; [exit code 75](#exceptions) marks a download or lock error where a
  retry can help, such as a network error, a 5xx status or a lock that another install holds.

## Installation

Requirements:
- PHP 8.4 or later, with `ext-posix` and `proc_open()`; the package itself needs no `ext-zip`. Ubuntu
  24.04 ships [PHP 8.3](https://packages.ubuntu.com/noble/php); there, install PHP 8.4, such as
  from `ppa:ondrej/php`.
- `curl`, plus Info-ZIP UnZip 6.00 and ZipInfo 3.00 from the `unzip` package, whose versions
  [safe-unzip](https://github.com/sorge-it/safe-unzip) checks. macOS ships both; on Debian and
  Ubuntu, `deps` installs them. On Alpine, `deps` installs neither: the system's pair needs no
  download, and `pin` there needs `apk add curl unzip`.
- glibc Linux or macOS, on x64 or arm64; on musl, the system's pair (see
  [Alpine](#alpine-and-other-musl-systems)). Composer refuses the package on Windows, where PHP has
  no `ext-posix`. On other platforms, the installer fails with an `InstallerException`.

```sh
composer require --dev sorge-it/cdp-browser-installer
vendor/bin/cdp-browser deps   # as root, if curl, unzip or a library of the pair is missing
```

Composer needs `unzip` or `ext-zip` to unpack the packages. The official `php` Docker images on
Debian have neither, and `composer require` fails with
`The zip extension and unzip/7z commands are both missing`. There, run
`apt-get update && apt-get install --yes unzip` as root before `composer require`: `deps` exists
only after it. To upgrade from 2.0.x, do each step of
[Upgrade from 2.0.x](https://github.com/sorge-it/cdp-browser-installer/blob/main/CHANGELOG.md#upgrade-from-20x);
from 1.x, first those of
[Upgrade from 1.x](https://github.com/sorge-it/cdp-browser-installer/blob/main/CHANGELOG.md#upgrade-from-1x),
then those of Upgrade from 2.0.x.

## Quick start

```sh
vendor/bin/cdp-browser pin VERSION                     # writes the pin file .chrome-version; commit it
vendor/bin/cdp-browser install                         # in each checkout, container and CI run
vendor/bin/cdp-browser path chrome-headless-shell      # prints the browser path
vendor/bin/cdp-browser path chromedriver               # prints the ChromeDriver path
```

- For `VERSION`, take the Stable version from the
  [Chrome for Testing dashboard](https://googlechromelabs.github.io/chrome-for-testing/). `pin`
  downloads eight archives, one for each binary on each of the four platforms, and writes the
  version and each SHA-256.
- `install` downloads the two archives for this platform. The
  [text demo](https://github.com/sorge-it/cdp-browser-installer/blob/main/demo/output/demo.txt)
  shows a first `install` with the download and a second one from the cache. `install`
  and `pin` refuse to run as root without `--allow-root` (see [Commands](#commands)).

Give the paths to your test tool: a WebDriver tool needs both, a tool for the Chrome DevTools
Protocol (CDP) only the browser. The
package contains no Pest or PHPUnit plugin, and the test tool starts the binaries.

## Works with

Run `vendor/bin/cdp-browser install` before the examples. Otherwise `binaries()` installs
chrome-headless-shell and ChromeDriver first, without output, and the first test waits for the
download. Each script is a complete file for the root of the project. It reads `Pin::FILE`, `.chrome-version`, from the working directory;
for Mink, the shell command does. The base classes go to `tests/` and read the pin file one level up.

- A WebDriver tool (Dusk, Panther, php-webdriver) starts the pinned ChromeDriver and gives it the
  browser path; ChromeDriver starts the browser. A CDP tool (chrome-php, Mink) starts the browser
  or connects to it. You need no other ChromeDriver, such as the one that
  `php artisan dusk:chrome-driver` or `vendor/bin/bdi detect drivers` installs.
- On Linux, each example turns off the sandbox (see
  [Run the browser and ChromeDriver](#run-the-browser-and-chromedriver)).
- The examples for Dusk and php-webdriver pass `--headless`: `chrome-headless-shell` accepts it,
  and Alpine's full Chromium needs it. Panther passes it itself. `chrome-headless-shell` shows no
  window, so `DUSK_HEADLESS_DISABLED` and `PANTHER_NO_HEADLESS` do not open one. For a window, use
  the system's Chrome with a ChromeDriver of the same version, and remove `--headless`.

### Laravel Dusk (laravel/dusk)

[`laravel/dusk`](https://packagist.org/packages/laravel/dusk) starts ChromeDriver in `prepare()`
of `tests/DuskTestCase.php` and connects to it in `driver()`. In a new project, write that file
with the class below, and put each test in `tests/Browser/`. Do not run `php artisan dusk:install`:
it writes the same file, and it installs its own ChromeDriver. With Pest, add what `dusk:install`
adds to `tests/Pest.php`: `pest()->extend(Tests\DuskTestCase::class)->in('Browser');`.
After an earlier `dusk:install`, keep your file. Pass the pinned ChromeDriver to `useChromedriver()`
in `prepare()`. Pass the browser path to `setBinary()` in `driver()`. Add `--no-sandbox` on Linux.
Add `$binaries`, `binaries()` and each `use` line of the class below that the file does not have.

`binaries()` reads the environment once, in `prepare()`, where it holds the values of `.env` under
`php artisan dusk`, not under `vendor/bin/phpunit`: set the `CDP_BROWSER_*` variables in the shell,
the CI job or the Dockerfile. The class keeps the window size of Dusk's own stub, 1920×1080;
without it, `chrome-headless-shell` renders pages at 800×600. It starts ChromeDriver on this
machine, not in another container, such as the Selenium service of Laravel Sail. Run the tests
with `php artisan dusk`. `visit()` loads a path below `APP_URL`, so a server must serve the app
while the tests run. Set `APP_URL` to its address, such as `http://127.0.0.1:8000` for
`php artisan serve` ([Laravel's documentation](https://laravel.com/framework/docs/dusk#continuous-integration)).
Dusk stops the browser and ChromeDriver after the tests of each class. Dusk needs `ext-zip`, and through `php-webdriver/webdriver` also `ext-curl`.

```php
<?php

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
    private static ?Binaries $binaries = null;

    #[BeforeClass]
    final public static function prepare(): void
    {
        static::useChromedriver(self::binaries()->path(Binary::Chromedriver));
        static::startChromeDriver(['--port=9515']);
    }

    protected function driver(): RemoteWebDriver
    {
        $options = new ChromeOptions()->setBinary(self::binaries()->path(Binary::ChromeHeadlessShell));
        $options->addArguments(PHP_OS_FAMILY === 'Linux' ? ['--headless', '--no-sandbox'] : ['--headless']);
        $options->addArguments(['--window-size=1920,1080']);

        return RemoteWebDriver::create('http://localhost:9515', DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options));
    }

    private static function binaries(): Binaries
    {
        // Once, in prepare(), where getenv() holds .env only under php artisan dusk.
        return self::$binaries ??= Installer::fromEnvironment(getenv())->binaries(dirname(__DIR__).'/'.Pin::FILE, Platform::current());
    }
}
```

### Symfony Panther (symfony/panther)

[`symfony/panther`](https://packagist.org/packages/symfony/panther) starts ChromeDriver from the
path that you pass to `Client::createChromeClient()`. It reads the sandbox setting from
`$_SERVER['PANTHER_NO_SANDBOX']` when it creates the client, and the browser path from
`$_SERVER['PANTHER_CHROME_BINARY']` when the client starts; set both before you create the client.
Panther passes `--port` to ChromeDriver itself. Panther needs `ext-dom` and `ext-libxml`, and
through `php-webdriver/webdriver` also `ext-curl` and `ext-zip`. In a script, create the client as
below; in a PHPUnit test, use the base class under [PantherTestCase](#symfony-panther-with-panthertestcase).

```php
<?php

use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Panther\Client;

require __DIR__.'/vendor/autoload.php';

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

### Symfony Panther with PantherTestCase

`PantherTestCase::createPantherClient()` takes no ChromeDriver path; it starts the first
`chromedriver` that it finds in `PATH`, `./drivers` or `./vendor/bin`. Before the first test of each
class, the base class below sets the browser path and the sandbox setting, and puts the directory
of the pinned ChromeDriver first in `PATH` until the PHPUnit process ends. Write it to
`tests/PantherTestCase.php`, and let each Panther test extend it instead of Panther's class. If
`autoload-dev` maps `tests/` to a namespace other than `App\Tests`, change the `namespace` line.

```php
<?php

namespace App\Tests;

use PHPUnit\Framework\Attributes\BeforeClass;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;
use Symfony\Component\Panther\PantherTestCase as BaseTestCase;

abstract class PantherTestCase extends BaseTestCase
{
    #[BeforeClass]
    final public static function usePinnedPair(): void
    {
        $binaries = Installer::fromEnvironment(getenv())->binaries(dirname(__DIR__).'/'.Pin::FILE, Platform::current());
        $_SERVER['PANTHER_CHROME_BINARY'] = $binaries->path(Binary::ChromeHeadlessShell);
        $_SERVER['PANTHER_NO_SANDBOX'] = PHP_OS_FAMILY === 'Linux' ? '1' : '0';
        // createPantherClient() starts the first chromedriver that it finds in PATH.
        putenv('PATH='.dirname($binaries->path(Binary::Chromedriver)).PATH_SEPARATOR.getenv('PATH'));
    }
}
```

### php-webdriver/webdriver

[`php-webdriver/webdriver`](https://packagist.org/packages/php-webdriver/webdriver) starts the
pinned ChromeDriver, and ChromeDriver starts the browser from the path in `ChromeOptions`. Give
`ChromeDriverService` the port twice: as its second argument, and as `--port` in its arguments.
The service passes only the arguments to ChromeDriver; without `--port`, ChromeDriver listens
on a random port. `quit()` stops the browser and ChromeDriver. php-webdriver needs `ext-curl` and `ext-zip`.

```php
<?php

use Facebook\WebDriver\Chrome\ChromeDriver;
use Facebook\WebDriver\Chrome\ChromeDriverService;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

require __DIR__.'/vendor/autoload.php';

$binaries = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current());
$options = new ChromeOptions()->setBinary($binaries->path(Binary::ChromeHeadlessShell));
$options->addArguments(PHP_OS_FAMILY === 'Linux' ? ['--headless', '--no-sandbox'] : ['--headless']);
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
the path that `binaries()` returns for `Binary::ChromeHeadlessShell`. It needs `ext-sockets`.

```php
<?php

use HeadlessChromium\BrowserFactory;
use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

require __DIR__.'/vendor/autoload.php';

$binary = Installer::fromEnvironment(getenv())->binaries(Pin::FILE, Platform::current())->path(Binary::ChromeHeadlessShell);
$browser = new BrowserFactory($binary)->createBrowser(['noSandbox' => PHP_OS_FAMILY === 'Linux']);

try {
    $page = $browser->createPage();
    // Drive the page.
} finally {
    $browser->close();
}
```

### Mink (dmore/chrome-mink-driver)

[`dmore/chrome-mink-driver`](https://packagist.org/packages/dmore/chrome-mink-driver) connects Mink
to an already running browser; `dmore/behat-chrome-extension` uses this driver. The driver needs
`ext-curl` and `ext-mbstring`. Start the browser with a debugging port and a profile directory, and
add that directory to `.gitignore`. On Linux, the first line adds `--no-sandbox`. The second line
waits until `http://127.0.0.1:9222/json/version` responds. If another process listens on port 9222 before the
start, the browser listens on `[::1]:9222`, and the driver talks to the other process. Stop the
browser after the tests.

```sh
"$(vendor/bin/cdp-browser path chrome-headless-shell)" --headless --remote-debugging-port=9222 --user-data-dir="$PWD/.chrome-profile" $([ "$(uname)" = Linux ] && echo --no-sandbox) &
curl --silent --fail --retry 20 --retry-connrefused --retry-delay 1 --output /dev/null http://127.0.0.1:9222/json/version
```

```php
<?php

use Behat\Mink\Session;
use DMore\ChromeDriver\ChromeDriver;

require __DIR__.'/vendor/autoload.php';

$session = new Session(new ChromeDriver('http://127.0.0.1:9222', null, 'http://localhost'));
$session->start();

try {
    // Drive the page.
} finally {
    $session->stop();
}
```

### The workflow `works-with`

The workflow [`works-with`](https://github.com/sorge-it/cdp-browser-installer/actions/workflows/works-with.yml)
runs the newest release of each tool in this section, as a user that is not root: the
job `works-with` on Linux x64 and arm64 and on macOS arm64, the job `works-with-alpine` on Alpine
x64 and arm64. It runs each Monday, after each merge, and on each pull request that changes the pin
file, the installer, the workflow or its scripts. Each tool loads a page and reads one element; the
browser, and ChromeDriver for a WebDriver tool, must report the version of the pair; the Dusk test
checks the window size. When a new release of a tool fails, require the version of the last green
run in your project. CI compares each example in this section with its script in [`works-with/`](https://github.com/sorge-it/cdp-browser-installer/tree/main/works-with).

## GitHub Actions

Cache the archives under a key from the hash of the pin file. The file holds the version and each
SHA-256, so a new pin gives a new key. Cache only the archives, `*/*/*.zip`, not the whole
directory: `install` checks the SHA-256 of an archive each time before it unpacks it, but it does
not check a pair that it finds installed (see [Limits](#limits-of-the-security-model)). For any
test tool, take `CDP_BROWSER_PATH` and the three steps from `actions/cache` to `install` below.

### Laravel Dusk on GitHub Actions

This job runs `php artisan dusk` with the `DuskTestCase` from [Laravel Dusk](#laravel-dusk-laraveldusk).
It is adapted from the workflow in [Laravel's documentation](https://laravel.com/framework/docs/dusk#running-tests-on-github-actions)
as of 2026-11-22: it drops the names of the workflow and its steps, the database step and the
`DB_*` variables, and its actions may be at other major versions than Laravel's. It
sets `CDP_BROWSER_PATH` and installs PHP 8.4, which the package needs, with
[`shivammathur/setup-php`](https://github.com/shivammathur/setup-php). The cache, `deps` and
`install` replace the steps `Upgrade Chrome Driver` and `Start Chrome Driver`. One step, not two,
uploads the screenshots and console logs as the artifact `dusk`. Add the database that your app needs; `php artisan migrate` goes after `composer install`.

```yaml
on: [push]
jobs:
  dusk-php:
    runs-on: ubuntu-latest
    env:
      APP_URL: "http://127.0.0.1:8000"
      MAIL_MAILER: log
      CDP_BROWSER_PATH: ${{ github.workspace }}/.cache/cdp-browser
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: cp .env.example .env
      - run: composer install --no-progress --prefer-dist --optimize-autoloader
      - run: php artisan key:generate
      - uses: actions/cache@v6
        with:
          path: .cache/cdp-browser/*/*/*.zip
          key: cdp-browser-${{ runner.os }}-${{ runner.arch }}-${{ hashFiles('.chrome-version') }}
      - run: sudo "$(command -v php)" vendor/bin/cdp-browser deps
      - run: vendor/bin/cdp-browser install
      - run: php artisan serve --no-reload &
      - run: php artisan dusk
      - if: failure()
        uses: actions/upload-artifact@v7
        with:
          name: dusk
          path: |
            tests/Browser/screenshots
            tests/Browser/console
```

## Use from PHP

```php
<?php

use SorgeIt\CdpBrowserInstaller\Binary;
use SorgeIt\CdpBrowserInstaller\DownloadException;
use SorgeIt\CdpBrowserInstaller\Installer;
use SorgeIt\CdpBrowserInstaller\LockException;
use SorgeIt\CdpBrowserInstaller\Pin;
use SorgeIt\CdpBrowserInstaller\Platform;

require __DIR__.'/vendor/autoload.php';

$installer = Installer::fromEnvironment(getenv());

try {
    $binaries = $installer->binaries(Pin::FILE, Platform::current());
} catch (DownloadException|LockException) {
    // A retry can help, such as after a network error, a 5xx status or a lock timeout.
    $binaries = $installer->binaries(Pin::FILE, Platform::current());
}

$browser = $binaries->path(Binary::ChromeHeadlessShell);
$chromedriver = $binaries->path(Binary::Chromedriver);
```

`binaries()` checks and returns the system's pair, or returns the paths of the pinned
chrome-headless-shell and ChromeDriver and installs them first if a binary or the stamp of this
pin is missing. The example retries once after a `DownloadException` or a `LockException`; any other `InstallerException` reaches the caller. To print each URL before
its download, as `install` does, pass a closure as `onDownload`. Without the
[environment variables](#environment-variables), pass each value to the constructors under
[API](#api), with an absolute cache directory that this user can write to.

## Run the browser and ChromeDriver

Start each binary from the path that `path` prints, not through a symbolic link: on macOS, Chrome
looks for its data files next to the path it was started from.

On Ubuntu 23.10 and later, AppArmor blocks the unprivileged user namespaces that the sandbox of a
Chrome for Testing binary needs
([Puppeteer's troubleshooting](https://pptr.dev/troubleshooting#issues-with-apparmor-on-ubuntu),
[LP #2046477](https://bugs.launchpad.net/bugs/2046477)), and most containers do not allow them.
Chrome then fails with `No usable sandbox!`. There, add `--no-sandbox`, as the `--dump-dom` command
below does on Linux; the installer does not decide that for you. Both binaries report the pinned
version, and the browser loads a page:

```sh
"$(vendor/bin/cdp-browser path chrome-headless-shell)" --version   # Google Chrome for Testing <version>
"$(vendor/bin/cdp-browser path chromedriver)" --version            # ChromeDriver <version> (…)
"$(vendor/bin/cdp-browser path chrome-headless-shell)" --headless $([ "$(uname)" = Linux ] && echo --no-sandbox) --dump-dom 'data:text/html,<p>ok</p>'
```

A WebDriver tool that connects to a running ChromeDriver needs it started with a port, such as
`"$(vendor/bin/cdp-browser path chromedriver)" --port=9515`. Without the browser path as `binary`
in `goog:chromeOptions`, ChromeDriver looks for a system-installed Chrome.

## The pin file

```json
{
    "version": "<version>",
    "chrome-headless-shell": {
        "linux64": "<SHA-256>",
        "linux-arm64": "<SHA-256>",
        "mac-x64": "<SHA-256>",
        "mac-arm64": "<SHA-256>"
    },
    "chromedriver": {
        "linux64": "<SHA-256>",
        "linux-arm64": "<SHA-256>",
        "mac-x64": "<SHA-256>",
        "mac-arm64": "<SHA-256>"
    }
}
```

The pin file names one version and, for each binary and each platform, the SHA-256 of its
archive. `install` and `path` refuse a pin file from release 1.x with exit code 1, and the message
names the command that writes a new one: `vendor/bin/cdp-browser pin` with the version of the old
file, and with `--pin-file` for a file other than `.chrome-version`. Chrome for Testing publishes no SHA-256
and no signature: its
[JSON endpoints](https://github.com/GoogleChromeLabs/chrome-for-testing#json-api-endpoints)
list only a platform and a URL for each download. So `pin` computes the SHA-256 from its own
download (see [Limits](#limits-of-the-security-model)). Commit the file and review each change to
it: the pin is the point where you trust Google.

### Update the pin

1. Run `vendor/bin/cdp-browser pin VERSION` with a version from the
   [Chrome for Testing dashboard](https://googlechromelabs.github.io/chrome-for-testing/).
2. Review the diff of `.chrome-version`, the version and the eight SHA-256 values, and commit it in
   a pull request.
3. The next `install` puts the new chrome-headless-shell and ChromeDriver under `<cache>/<version>/`, and a CI cache keyed on the pin
   file gets a new key. No command removes the directory of the old version; remove it yourself.

## Security

The package protects the download: the archive from the one known source, with the pinned
SHA-256. The host is responsible for the rest: whoever can write to the cache, `PATH` or PHP's
environment decides what runs. [Limits](#limits-of-the-security-model) lists what the installer
does not check. Each rule names its code; a test in `tests/` covers each rule except rule 7.

1. **Pinned.** One version, one SHA-256 for each binary and each platform, in a file in your
   repository (`Pin`). `pin` first compares each download with the MD5 in `x-goog-hash` (`PinCommand`).
2. **One source.** Downloads come only from `https://storage.googleapis.com/chrome-for-testing-public/`
   (`Download::SOURCE`). `curl -q` reads no `.curlrc`; `--proto =https --tlsv1.2` allows only HTTPS
   with TLS 1.2 or later, with TLS verification on; `--max-filesize` aborts a download above 500 MiB.
   Any status other than 200 fails the install; without `--location`, this includes a redirect.
   `install` downloads into a `.part-*` file of the run and deletes it when the download fails,
   also after a timeout. If it cannot delete the file, as in a directory that turned read-only, the
   next install that unpacks removes it once its last write is 120 seconds old. `pin` removes its
   own directory with each download in it.
3. **Checked before unpacking.** For each binary in turn, the install downloads the archive into a
   `.part-*` file of its own run, or renames the archive that a CI cache restored to one. A SHA-256
   that differs from the pin file fails the install and deletes the archive (`Installer::verify`);
   otherwise the install unpacks the file and moves it into the cache. A pair whose two binaries and
   stamp match the pin file counts as installed, and its archives are not read again.
4. **No entry outside the target.** Before `unzip` writes a byte,
   [safe-unzip](https://github.com/sorge-it/safe-unzip) parses `zipinfo -v` with a closed grammar,
   checks the archive against its "standard ZIP" profile, and decompresses each entry into a pipe
   to compare its size and CRC-32 with the listing, so no zip bomb reaches the disk. It refuses,
   among others, links and devices, encrypted entries, a name outside `A-Z a-z 0-9 . _ - /` or with
   a `.` or `..` segment, two entries with the same name, more than 10,000 entries and more than
   2 GiB unpacked. After `unzip`, the directory must hold exactly the files of the listing, with
   their sizes and CRC-32, and no link; safe-unzip sets each mode without write access for group
   and others. Every tool runs with `LC_ALL=C`, and without `UNZIP`, `UNZIPOPT`, `ZIPINFO` and
   `ZIPINFOOPT`, which would add options from the environment.
5. **One install at a time.** A lock file per version and platform makes a second install wait
   for the first one, and then find the pair installed; an install that finds the pair installed
   takes no lock. The stamp `.sha256-pair` holds the SHA-256 of each archive. `install` unpacks
   both binaries again when a binary or the stamp is missing, or the stamp names another archive.
   It removes the stamp before it moves the first tree, and writes it when both trees are in
   place, so a pair with only one new binary counts as not installed. Each new tree moves in with
   one rename, and the old tree is removed (`Installer`); a binary that still runs from it keeps
   only the files it has open. The next run that unpacks removes the files of an interrupted run.
   For a shared file system, see [Limits](#limits-of-the-security-model).
6. **Root only for the package manager.** Only `deps` needs root. It installs a fixed package list
   from the system's signed sources (`SystemLibraries`). It finds `apk` or `apt-get` in `PATH`,
   passes the caller's environment plus `DEBIAN_FRONTEND=noninteractive`, and runs it without a
   timeout, so that no timeout leaves dpkg half configured; Ctrl-C or SIGKILL still can. `install`
   and `pin` refuse to run as root without `--allow-root` (`Cli::refuseRoot`); `path`, and
   `install` with the system's pair, write nothing; `path` and the PHP API do not check the user.
7. **No shell.** Every process starts from an argument list (`CommandLine`). The installer looks a
   tool up in the `PATH` that the process gets, where a relative or empty entry counts from the
   working directory; without a `PATH`, it looks in `/usr/bin` and `/bin`. safe-unzip looks for
   `zipinfo` and `unzip` in the absolute entries only. A timeout or a signal fails the install, as
   a failing exit code does.

### Limits of the security model

- **Trust on first use.** `pin` trusts the bytes that Google serves at that moment. The SHA-256
  detects every later change, but not a wrong first download. Google Cloud Storage sends an MD5 and
  a CRC32C for each archive in the [`x-goog-hash`](https://docs.cloud.google.com/storage/docs/xml-api/reference-headers#xgooghash)
  header. `pin` compares the MD5 with its download and writes no pin file when they differ. For a
  platform without an MD5, it prints one line that says so, and still writes the pin file. The MD5
  and the archive come from the same server: the MD5 detects a broken download, not a changed source.
- **The lock on a shared file system.** On a Docker Desktop bind mount on macOS, `flock()` does not
  lock (measured on 2026-10-30). There, of two installs that run at the same
  time, one can fail; run it again. NFS and Linux bind mounts are not measured.
- **No later check of an installed pair.** The SHA-256 covers each archive before it is unpacked,
  not the files after that. A changed file in an installed pair stays undetected while its binaries
  and stamp match the pin file. To check it again, remove `<cache>/<version>/` and run `install`.

## When to use it and when not to

Use it for PHP browser tests that drive Chrome through a WebDriver or CDP tool, such as those under
[Works with](#works-with), where each checkout, container and CI run needs the same pair, you want
to review each new browser version and each SHA-256 in a pull request, as a change to the
[pin file](#the-pin-file), and the browser should need no Node.

Do not use it for:

- tests on Windows: the package requires `ext-posix`, which PHP on Windows does not have. Use
  `@puppeteer/browsers`, which installs both binaries for Windows;
- Firefox, GeckoDriver or another browser: the package installs only `chrome-headless-shell` and
  `chromedriver`. Use `@puppeteer/browsers` for Firefox and `dbrekelmans/bdi` for GeckoDriver;
- tests that need a browser window: `chrome-headless-shell` shows no window. Use the system's
  Chrome with a ChromeDriver of the same version, as under [Works with](#works-with);
- starting or stopping the browser or ChromeDriver: the package starts neither binary for a test;
  with the system's pair, `install`, `path` and `binaries()` run each binary once with
  `--version`. Your test tool starts them, as under [Works with](#works-with);
- the pinned version on Alpine or another musl system: Chrome for Testing does not start on musl,
  and the installer uses the system's pair in the system's version (see
  [Alpine](#alpine-and-other-musl-systems)). Run the tests on a glibc image, such as Debian;
- a network that cannot reach `storage.googleapis.com`: downloads come only from Chrome for Testing
  (rule 2 under [Security](#security)), and no option names a mirror. Install a pair yourself and
  set `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER` to it.

### Scope

The package gives a PHP browser test the same checked pair in each checkout, container and CI run.
The reference is Chrome for Testing: its versions, its platforms and its archives. Where the scope
and the reference disagree, the scope wins.

This question decides whether a behavior is in scope:

> Does a PHP browser test on glibc Linux or macOS need it to get the pinned, checked pair from
> Chrome for Testing, or to run where that pair cannot start? Yes: in scope. No: out of scope.

| Behavior | Scope |
|---|---|
| Download `chrome-headless-shell` and `chromedriver` of one version from Chrome for Testing | in |
| Pin the version and the SHA-256 of each archive in a file in the repository | in |
| Check each archive before it is unpacked, also one from a CI cache | in |
| Lock a parallel install of the same version and platform | in |
| Install on glibc Linux and macOS, on x64 and arm64 | in |
| Install `curl`, `unzip` and the libraries of the pair, or Alpine's pair, as root (`deps`) | in |
| Use the system's pair through `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER`, as on musl | in |
| Install on Windows | out |
| Install another browser or driver, such as `chrome`, Firefox or GeckoDriver | out |
| Start or stop the browser or ChromeDriver for a test | out |
| Provide a plugin for Pest or PHPUnit | out |
| Download from another source or a mirror | out |
| Install the pinned version on musl | out |
| Find the newest Stable and pin it without a human | out |

## Comparison with other packages

[Chrome for Testing's README](https://github.com/GoogleChromeLabs/chrome-for-testing#whats-the-easiest-way-to-download-chrome-for-testing-binaries) names `@puppeteer/browsers` as "the easiest way" to download it.
That package, Puppeteer and Playwright run on Node. Each row names the newest release on
2026-11-18, with the date of its GitHub release, otherwise of npm or Packagist. Each cell comes from
the code, the documentation or the CI of that release; each path in parentheses is a file in that
package's repository.

| Package | Version | Installs | Node | Pins a version | Checks a SHA-256 | Locks a parallel install | Windows |
|---|---|---|---|---|---|---|---|
| [**`sorge-it/cdp-browser-installer`**](https://github.com/sorge-it/cdp-browser-installer) | this README | `chrome-headless-shell` and `chromedriver` of one version from Chrome for Testing, for glibc Linux and macOS on x64 and arm64 | no | yes, in the pin file ([rule 1](#security)) | yes, each time before it unpacks an archive ([rule 3](#security)) | yes, for each version and platform ([rule 5](#security)) | no: it [requires `ext-posix`](https://github.com/sorge-it/cdp-browser-installer/blob/main/composer.json) |
| [`@puppeteer/browsers`](https://github.com/puppeteer/puppeteer) | 3.2.7, 2026-11-10 | `chrome-headless-shell`, `chromedriver` and other browsers (`packages/browsers/src/browser-data/types.ts`) | 22.12 or later (`packages/browsers/package.json`) | in the command line, not in a file: `install <browser>@<build>`, or an alias such as `stable` (`packages/browsers/src/CLI.ts`) | only when the caller of the API passes `expectedHash` (`packages/browsers/src/install.ts`); the CLI passes none (`packages/browsers/src/CLI.ts`) | no (`packages/browsers/src/install.ts`) | yes: `win32` and `win64` (`packages/browsers/src/browser-data/types.ts`) |
| [`puppeteer`](https://github.com/puppeteer/puppeteer) | 25.18.1, 2026-11-17 | `chrome` and `chrome-headless-shell`, through `@puppeteer/browsers`, on `npm install` (`docs/guides/installation.md`, `packages/puppeteer/src/node/install.ts`) | 22.12 or later (`docs/guides/system-requirements.md`) | yes, one version for each release (`packages/puppeteer-core/src/revisions.ts`); a configuration file can name another (`packages/puppeteer/src/getConfiguration.ts`) | no: its install passes no hash (`packages/puppeteer/src/node/install.ts`) | no (same file) | yes, on x64 (`docs/guides/system-requirements.md`) |
| [`playwright-core`](https://github.com/microsoft/playwright) | 1.65.1, 2026-11-13 | by default `chromium-headless-shell`, `chromium`, `firefox` and `webkit`, from its own mirror `cdn.playwright.dev` (`packages/playwright-core/browsers.json`, `packages/playwright-core/src/server/registry/index.ts`) | 20 or later (`packages/playwright-core/package.json`) | yes, in `packages/playwright-core/browsers.json` | no: `packages/playwright-core/src/server/registry/` contains no `createHash`, `sha256`, `checksum` or `integrity` | yes (`packages/playwright-core/src/server/registry/index.ts`) | yes: `win64` (same file) |
| [`pestphp/pest-plugin-browser`](https://github.com/pestphp/pest-plugin-browser) | 5.2.0, 2026-10-29 | no browser; its message asks for `npm install playwright && npx playwright install` (`src/Exceptions/PlaywrightNotInstalledException.php`) | yes, for Playwright from npm (same file) | — | — | — | not stated in [its docs](https://pestphp.com/docs/browser-testing); its code has branches for Windows (`src/Support/Shell.php`, `src/Support/TraceReporter.php`), and its CI runs on Ubuntu only (`.github/workflows/tests.yml`) |
| [`dbrekelmans/bdi`](https://github.com/dbrekelmans/browser-driver-installer) | 1.4.1, 2025-11-03 | ChromeDriver and GeckoDriver (`README.md`) | no | in the command line, not in a file: `driver:chromedriver --driver-version=<version>`; else the latest, or the one that matches the browser it finds (same file) | no (`src/Driver/ChromeDriver/Downloader.php`) | no (same file) | yes: ChromeDriver for `win32` (same file) |
| [`laravel/dusk`](https://github.com/laravel/dusk) | 8.9.0, 2026-11-17 | ChromeDriver (`src/Console/ChromeDriverCommand.php`) | no | in the command line, not in a file: `dusk:chrome-driver <version>`; else the latest Stable, or with `--detect` the one that matches the installed Chrome (same file) | no: it writes the download through Guzzle and checks only its status (same file) | no (same file) | yes: ChromeDriver for `win32` (`src/OperatingSystem.php`) |
| [`symfony/panther`](https://github.com/symfony/panther) | 2.4.0, 2026-01-08 | no browser; it names `bdi` for ChromeDriver (`src/ProcessManager/ChromeManager.php`) | no | — | — | — | yes: a CI job on `windows-latest` (`.github/workflows/ci.yml`) |
| [`chrome-php/chrome`](https://github.com/chrome-php/chrome) | 1.16.2, 2026-11-04 | no browser; it finds a Chrome by `CHROME_PATH` or a system path (`src/AutoDiscover.php`) | no | — | — | — | yes: it reads the path of Chrome from the registry (same file) |
| [`spatie/browsershot`](https://github.com/spatie/browsershot) | 5.5.0, 2026-11-06 | no browser; it uses the npm package `puppeteer` (`docs/requirements.md`) | 22 or later (same file) | — | — | — | yes: it starts Node in its own way on Windows (`src/Browsershot.php`) |

## Reference

### Commands

| Command | Does | Runs as |
|---|---|---|
| `pin VERSION` | Checks that it can write the pin file and that the real path of its temporary directory has no refused character. Then it downloads one archive for each binary and each platform, compares its MD5 with the one the storage sends, unpacks it once with the checks of `install`, and writes the version and each SHA-256 to the pin file | a normal user; root with `--allow-root` |
| `install` | Installs the pinned chrome-headless-shell and ChromeDriver for this platform, or checks the system's pair in `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER`, and prints both paths in its message; a script reads a path from `path`. Before each download, it prints `Download <url>`, as `pin` does | a normal user; root with `--allow-root`, or with the system's pair |
| `path <binary>` | Prints the path of `chrome-headless-shell` or `chromedriver`; without a name it fails and names both; it installs nothing; for the pinned pair, it fails until `install` has run | any user, including root |
| `deps` | Installs `curl`, `unzip` and the libraries of the pair through apt, or Alpine's `chromium` and `chromium-chromedriver` through apk; on macOS it does nothing. For the supported distributions, see [Compatibility](#compatibility) | root |

| Option | Commands | Does |
|---|---|---|
| `--pin-file <file>` | `pin`, `install`, `path` | the pin file; the default is `.chrome-version` in the working directory |
| `--allow-root` | `pin`, `install` | lets the command run as root |
| `--version` | the application | prints the version that Composer installed, or why it knows none |
| `-v` | each command | on an error: Symfony's format, with the class, file, line, trace and usage |

A command that fails prints the message of its exception to the error output, unchanged, and ends
with the exit code that [Exceptions](#exceptions) names for its class. Any other exception is shown
in Symfony's format and ends with its code, capped at 255, if that is above 0, else with exit
code 1. A PHP `Error`, such as a `TypeError` from a bug, is not caught: PHP prints it as a fatal
error and ends with exit code 255.

`install` and `pin` refuse to run as root: a pair or a pin file owned by root breaks the next run
as a normal user, who cannot replace it. Where only root runs, as in a Dockerfile `RUN`,
`--allow-root` accepts that risk; no environment variable sets it. A later run as another user with
the same `CDP_BROWSER_PATH` finds the pair installed. The image needs the pin file and `vendor/`
before this step, and `CDP_BROWSER_PATH` keeps the pair out of `/root`:

```dockerfile
ENV CDP_BROWSER_PATH=/opt/cdp-browser
RUN vendor/bin/cdp-browser deps && vendor/bin/cdp-browser install --allow-root
```

### Environment variables

| Variable | Default | Does |
|---|---|---|
| `CDP_BROWSER_CHROME` | none | an absolute path to the system's browser, such as `/usr/bin/chromium`. Only together with `CDP_BROWSER_CHROMEDRIVER`: with both, `install` and `path` read no pin file, download nothing and need no cache |
| `CDP_BROWSER_CHROMEDRIVER` | none | an absolute path to the system's ChromeDriver, such as `/usr/bin/chromedriver`. Only together with `CDP_BROWSER_CHROME` |
| `CDP_BROWSER_PATH` | `$XDG_CACHE_HOME/cdp-browser` if `XDG_CACHE_HOME` is absolute, else `~/.cache/cdp-browser` | the cache directory (see below) |
| `CDP_BROWSER_TIMEOUT` | `900` | the timeout in seconds for each tool that `install`, `path` and `pin` start: one `curl` download, each run of `zipinfo` and `unzip`, and `--version` of each binary in the system's pair. `deps` has no timeout |
| `CDP_BROWSER_LOCK_TIMEOUT` | `600` | how many seconds an install waits for another install in the same directory, `<cache>/<version>/<platform>/`. `install` and `path` read it together with the cache; with the system's pair, and in `pin` and `deps`, it is not read |

The commands read the environment of their process, never a `.env` file;
`Installer::fromEnvironment()` reads only the array that it gets, such as `getenv()`. When only one
of `CDP_BROWSER_CHROME` and `CDP_BROWSER_CHROMEDRIVER` is set, `install` and `path` fail with exit
code 1, `Installer::fromEnvironment()` throws an `InstallerException`, and the message names the
other: a system browser with a pinned ChromeDriver, or the reverse, can differ in version. While
`CDP_BROWSER_BINARY` from release 1.x is set and not empty, they also fail, and the message names
both variables. `pin` and `deps` read none of the three.

Both timeouts take a whole number of seconds above 0, in digits only, such as `900`, not `15m`,
`+900` or `0900`; `0` would mean no limit for a tool but no wait at the lock. With any other value,
each command that reads the variable fails with an `InstallerException`; an empty variable counts
as not set. `curl` also aborts a download when the speed stays below 1 KiB/s for 60 seconds. The
lock timeout covers the whole install of the other run, whose tools can each take up to the tool
timeout; on a slow connection, set it above the duration of one install.

### Cache directory

`install` puts the pair under `<cache>/<version>/<platform>/`: `chrome-headless-shell-<platform>/`
and `chromedriver-<platform>/` side by side, each next to the archive it came from, and the stamp
`.sha256-pair` with the SHA-256 of both archives. The cache directory is `CDP_BROWSER_PATH` if set,
where a relative path counts from the working directory; else `$XDG_CACHE_HOME/cdp-browser` if
`XDG_CACHE_HOME` is absolute; else `~/.cache/cdp-browser`. As the
[XDG Base Directory Specification](https://specifications.freedesktop.org/basedir/latest/) asks, a
relative `XDG_CACHE_HOME`, also one that starts with `~`, is ignored.

`.` and `..` are resolved textually, without following symbolic links. The install fails when the
cache is the root directory, and when `CDP_BROWSER_PATH` or `HOME` starts with `~`, which only a
shell expands. It also fails before the download when the cache path or its real path contains
`*`, `?`, `[`, `]`, `\` or a line break, which safe-unzip would refuse in the path of the archive;
`pin` refuses them in the real path of its temporary directory. The constructor of `Installer`
checks its cache directory the same way, and takes no relative path.

In a container, set `CDP_BROWSER_PATH` to a directory in the project, so that the next container
finds the pair that the previous one installed. Add that directory to `.gitignore`, such as
`/.cache/`: it holds the archives and the unpacked pair. To remove an old version, see
[Update the pin](#update-the-pin).

### API

These classes and members are the API, and each class carries `@api`; SemVer covers them. Only a
new major release breaks them, and the CHANGELOG marks each break as **Breaking**; Composer's
`^2.0` takes no such release:

```php
namespace SorgeIt\CdpBrowserInstaller;

final readonly class Installer
{
    public const string OWN_CHROME = 'CDP_BROWSER_CHROME';
    public const string OWN_CHROMEDRIVER = 'CDP_BROWSER_CHROMEDRIVER';
    public const string TIMEOUT = 'CDP_BROWSER_TIMEOUT';
    public const string LOCK_TIMEOUT = 'CDP_BROWSER_LOCK_TIMEOUT';

    /** $cache: an absolute directory, or null for an installer that only checks the system's pair in $own. */
    public function __construct(CommandLine $commandLine, ?string $cache, ?Binaries $own = null, ?bool $musl = null, int $lockTimeout = 600);
    /** The installer as the environment configures it. A test tool starts here. @param array<string, string> $environment */
    public static function fromEnvironment(array $environment, CommandLine $commandLine = new CommandLine, ?bool $musl = null): self;
    /** The absolute cache directory that the environment names. @param array<string, string> $environment */
    public static function cache(array $environment): string;
    /** The pair to start: the system's pair, checked, or the pinned pair, installed if needed. @param (\Closure(string): void)|null $onDownload gets the URL before each download */
    public function binaries(string $pinFile, Platform $platform, ?\Closure $onDownload = null): Binaries;
    /** Installs the pinned pair unless it is installed, and returns its paths. @param (\Closure(string): void)|null $onDownload gets the URL before each download */
    public function install(Pin $pin, Platform $platform, ?\Closure $onDownload = null): Binaries;
    /** Where the pinned pair is, or goes, in the cache; it installs nothing. */
    public function paths(Pin $pin, Platform $platform): Binaries;
    /** Whether both binaries are there, and the stamp names the archives of this pin. */
    public function isInstalled(Pin $pin, Platform $platform): bool;
    /** The system's pair from the constructor or the environment, or null. */
    public function ownBinaries(): ?Binaries;
}

final readonly class Binaries
{
    public function __construct(string $chromeHeadlessShell, string $chromedriver);
    /** The absolute path of the binary to start. */
    public function path(Binary $binary): string;
}

enum Binary: string
{
    case ChromeHeadlessShell = 'chrome-headless-shell';
    case Chromedriver = 'chromedriver';

    /** The variable that names the system's binary: CDP_BROWSER_CHROME or CDP_BROWSER_CHROMEDRIVER. */
    public function variable(): string;
    /** Where Alpine's package puts the binary, such as /usr/bin/chromium. */
    public function alpinePath(): string;
    /** The top directory inside the archive, such as chrome-headless-shell-linux64. */
    public function directory(Platform $platform): string;
    /** The path inside the archive, such as chrome-headless-shell-linux64/chrome-headless-shell; not for starting the binary: Binaries::path() gives the installed binary. */
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
    /** Throws unless the version is four numbers separated by dots. */
    public static function checkVersion(string $version): void;
    /** Reads and checks a pin file; throws for a missing file and for one of release 1.x. */
    public static function read(string $file): self;
    /** Writes the pin file as JSON; a symbolic link stays, and its target gets the new content. */
    public function write(string $file): void;
    /** The pinned SHA-256 for the archive with this binary for this platform. */
    public function hash(Binary $binary, Platform $platform): string;
}

enum Platform: string
{
    case LinuxX64 = 'linux64';
    case LinuxArm64 = 'linux-arm64';
    case MacX64 = 'mac-x64';
    case MacArm64 = 'mac-arm64';

    /** The platform for PHP_OS_FAMILY and php_uname('m'); throws for any other platform. */
    public static function detect(string $family, string $machine): self;
    /** The platform that this PHP runs on: detect(PHP_OS_FAMILY, php_uname('m')). */
    public static function current(): self;
}

class InstallerException extends \RuntimeException { public const int EXIT_CODE = 1; }
final class DownloadException extends InstallerException { public const int EXIT_CODE = 75; }
final class ChecksumException extends InstallerException { public const int EXIT_CODE = 65; }
final class LockException extends InstallerException { public const int EXIT_CODE = 75; }
```

`$musl = null` detects musl as `fromEnvironment()` does; `true` or `false` sets it. The code of
each exception is its `EXIT_CODE`. Timeouts are in seconds. In the constructors, unlike in the
environment variables, `0` means no limit for a tool, and no wait at the lock; a value below 0
throws an `InstallerException`. `fromEnvironment()` takes the timeout of `$commandLine` and 600
seconds for the lock, unless the environment variables set other values. Each tool gets PHP's
environment plus the variables in `$environment`: `curl`, `rm`, `getconf`, the package manager,
each binary in the system's pair, and `zipinfo` and `unzip` through safe-unzip.

The rest is `@internal` and can change in any release: the command classes of `bin/cdp-browser`
(`Console\*`), the steps of an install (`Download`, `Unpack`, `Libc`, `SystemLibraries`,
`Environment`, `Files`), every method of `CommandLine` but its constructor, `Installer::onMusl()`, `Pin::option()`,
and the constructors of the exceptions. A new subclass of `InstallerException` never comes in
a patch release, and the CHANGELOG names it. A minor release can also move a cause to another
subclass, and so to another exit code, or change the exception that `getPrevious()` returns; the
CHANGELOG names each change. To catch every cause, catch `InstallerException`.

### Exceptions

Every exception that the API throws is an `InstallerException` (a `RuntimeException`) or one of its
three subclasses. Its message names the path or the value, and usually the next step. Callers catch
it; only the package throws it. Where safe-unzip, Symfony or PHP reports the cause, `getPrevious()`
returns that exception, one level deep. At the tool timeout (`CDP_BROWSER_TIMEOUT`), this is
Symfony's `ProcessTimedOutException` for `curl` and the other tools that the installer starts, and
safe-unzip's `ToolException` for `zipinfo` and `unzip`, which safe-unzip starts. When a tool that
the installer starts fails with an exit code other than 0, such as `curl` with 28 at its own
limits, `getPrevious()` returns Symfony's `ProcessFailedException`, whose `getProcess()->getExitCode()` returns that exit code.

| Class | Exit code | When | What the caller can do |
|---|---|---|---|
| `DownloadException` | 75 (`EX_TEMPFAIL`) | `curl` fails with a signal, at the tool timeout, or with an exit code other than 23, 60, 63 or 77, or the source responds with a status other than 200 or 404; no archive is left, only a `.part-*` file that the run could not delete (see rule 2 under [Security](#security)) | a retry can help after a network error or a 5xx status; the message names the status or the exit code. For a 3xx status, the message adds that the installer does not follow redirects |
| `ChecksumException` | 65 (`EX_DATAERR`) | the SHA-256 of an archive differs from the pin file; that archive is deleted and not unpacked, and the pair counts as not installed. In `pin`: the MD5 of a download differs from the one in `x-goog-hash`; no pin file is written | after a new download, a retry does not help: the bytes are not the pinned ones. If the run deleted an archive from the cache, a retry downloads it again. The message says where the archive came from and names the next step. In `pin`, a retry downloads again; the message names the binary, the platform and both MD5 values |
| `LockException` | 75 (`EX_TEMPFAIL`) | another install in the same directory held the lock past the lock timeout; or, where `flock()` does not lock, another install removed the checked archive of this install before this install moved it back into the cache (see [Limits of the security model](#limits-of-the-security-model)) | a retry helps once the other install has ended; after a lock timeout, you can also raise `CDP_BROWSER_LOCK_TIMEOUT` |
| `InstallerException` | 1 | every other cause: a value of the environment, an option or an argument; the user, such as root; the pin file, including one from release 1.x; a version that the source does not have for the platform (status 404); the platform or musl; `CDP_BROWSER_CHROME` or `CDP_BROWSER_CHROMEDRIVER`, including only one of them, and `CDP_BROWSER_BINARY` from release 1.x; a missing tool, and a tool other than `curl` that fails; safe-unzip refuses the archive or finds it broken, or the archive holds no binary for the platform; the file system; `curl` with exit code 23 (it cannot write the archive, such as on a full disk), 60 (a certificate that it does not trust), 63 (an archive above 500 MiB) or 77 (it cannot read its CA certificates); `path` without the name of a binary, or, for the pinned pair, before `install` has run | a human fixes the cause that the message names |

The exit codes 75 and 65 come from `sysexits.h`. safe-unzip reads an archive only after its
SHA-256 matched the pin file; in `pin`, the SHA-256 comes from the same bytes. So a refusal by
safe-unzip is an `InstallerException`, not a `ChecksumException`; its previous exception,
safe-unzip's `RefusedException`, names the rule in `$reason`. A failed removal in a `finally` does
not replace the first error. PHP's `TypeError` and `ValueError` are not exceptions of the package:
they mark a call that violates the signature, such as a path with a NUL byte. The API suppresses
the warning of a failed file-system call and throws its own exception, even under an error handler
that turns warnings into an `ErrorException`, as Laravel's does.

## Troubleshooting

| Message or symptom | Cause | Next step |
|---|---|---|
| `composer require` fails with `The zip extension and unzip/7z commands are both missing` | Composer has neither `unzip` nor `ext-zip` | install `unzip` first (see [Installation](#installation)) |
| `session not created: This version of ChromeDriver only supports Chrome version …` | a ChromeDriver and a browser of different versions | give the test tool both paths of the pair (see [Works with](#works-with)) |
| `No usable sandbox!` | Linux without user namespaces for Chrome's sandbox, as in most containers | add `--no-sandbox` (see [Run the browser and ChromeDriver](#run-the-browser-and-chromedriver)) |
| `session not created: Chrome instance exited` on Alpine | Alpine's full Chromium without `--headless` and without a display | add `--headless` (see [Alpine](#alpine-and-other-musl-systems)) |
| exit code 127 and `unsupported relocation type 1032` | Chrome for Testing on musl | use Alpine's pair (see [Alpine](#alpine-and-other-musl-systems)) |
| `There is no pin file at …` from a test | the test runs from another working directory | pass an absolute path, as the two base classes under [Works with](#works-with) do |
| `Another install in …` or `Another install removed it` | a slow install of the same version, or a shared file system | install again; after `Another install in …`, you can also raise `CDP_BROWSER_LOCK_TIMEOUT` (see [Environment variables](#environment-variables)) |
| a Dusk test sees the mobile layout of a page | `chrome-headless-shell` renders pages at 800×600 without `--window-size` | keep `--window-size` (see [Laravel Dusk](#laravel-dusk-laraveldusk)) |

## Compatibility

Each row names the CI jobs that test it, in
[`tests.yml`](https://github.com/sorge-it/cdp-browser-installer/blob/main/.github/workflows/tests.yml),
[`works-with.yml`](https://github.com/sorge-it/cdp-browser-installer/blob/main/.github/workflows/works-with.yml)
and [`chrome-for-testing.yml`](https://github.com/sorge-it/cdp-browser-installer/blob/main/.github/workflows/chrome-for-testing.yml).
Fixes come only for the latest minor release of 2.x (see the [security policy](https://github.com/sorge-it/cdp-browser-installer/security/policy)).

| What | Versions | CI job |
|---|---|---|
| PHP | 8.4 and 8.5; `composer.json` requires `^8.4` | `tests`: PHP 8.4 with the lowest and with the newest dependencies, PHP 8.5 with `composer.lock`; `suite-alpine` with PHP 8.4 and 8.5; `suite-debian` with PHP 8.4 |
| Symfony Console and Process | 7.4.8 or later, and 8 | `symfony-7` installs the lowest versions that `composer.json` allows, without the development packages, and runs the commands on the browser of the pin file for linux64; the suite runs on Symfony 8 |
| glibc Linux | x64 and arm64 | `browser` installs chrome-headless-shell and ChromeDriver and loads a page on Ubuntu; `system-libraries` runs `deps` on minimal images of Debian and Ubuntu |
| macOS | x64 and arm64 | `browser` on macOS, Intel and Apple silicon; `tests` runs the suite on macOS with Apple's `zipinfo` and `unzip` |
| Alpine (musl) | x64 and arm64, with Alpine's pair | `alpine`, `suite-alpine` and `works-with-alpine` |
| Windows | none | none |
| Test tools | the newest release of each tool under [Works with](#works-with) | `works-with` on Linux x64, Linux arm64 and macOS arm64; `works-with-alpine` on Alpine x64 and arm64 |
| Chrome for Testing | the version in the pin file of this repository, [`.chrome-version`](https://github.com/sorge-it/cdp-browser-installer/blob/main/.chrome-version) | `chrome-for-testing` each Monday and on each pin change |

**Older distributions.** `deps` uses the package names from after the 64-bit time transition, such
as `libasound2t64`, which exist on Debian 13 and on Ubuntu 24.04 and later. On an older release,
apt fails at the first unknown name, and so does `deps`; there, install the libraries that `ldd chrome-headless-shell chromedriver` lists yourself.

**Rosetta.** `Platform::current()` reads `php_uname('m')`. An x64 build of PHP that runs through
Rosetta 2 on Apple silicon reports `x86_64` and installs `mac-x64`, which then also runs through
Rosetta. Not measured; use an arm64 build of PHP.

### Alpine and other musl systems

Chrome for Testing is built against glibc and does not start on musl, not even with `gcompat`. On
Alpine arm64, `chrome-headless-shell` failed with exit code 127 and `unsupported relocation type
1032` (measured on 2026-10-31). Playwright
[does not support Alpine](https://playwright.dev/docs/docker).
[Puppeteer's troubleshooting guide](https://pptr.dev/troubleshooting#running-on-alpine)
installs Alpine's `chromium` and sets `PUPPETEER_EXECUTABLE_PATH` to it;
[Symfony's guide to Panther](https://symfony.com/doc/current/testing/end_to_end.html#docker-integration)
installs `chromium` and `chromium-chromedriver` with `apk`. This installer uses Alpine's pair too,
through two environment variables:

```sh
vendor/bin/cdp-browser deps   # as root: apk add --no-cache chromium chromium-chromedriver nss freetype harfbuzz ttf-freefont
export CDP_BROWSER_CHROME=/usr/bin/chromium CDP_BROWSER_CHROMEDRIVER=/usr/bin/chromedriver
vendor/bin/cdp-browser install
```

Without the two variables, `install` and `path` detect musl (`getconf`, then the loader in the
memory maps of the PHP process, then the loader file) and fail before any download; the message
names `deps` and both variables with their paths. On a musl system without apk, `deps` fails;
install Chromium and its ChromeDriver yourself and set both variables to their paths.

The version of the pair is Alpine's, not the one in the pin file. Alpine builds `chromium` and
`chromium-chromedriver` from one source package, so both have the same version.
`path chrome-headless-shell` prints the path in `CDP_BROWSER_CHROME`, such as `/usr/bin/chromium`.
It is the full Chromium, so without a display, start it with `--headless`, as the examples under
[Works with](#works-with) do; otherwise ChromeDriver fails with `session not created: Chrome instance exited`.
The user agent of Alpine's Chromium names only the major version, such as
`HeadlessChrome/<major>.0.0.0`. To check the browser:

```sh
/usr/bin/chromium --headless --no-sandbox --dump-dom 'data:text/html,<p>ok</p>'
```

## Support

Issues are turned off for this repository. Found a bug? Open a pull request with a failing test,
as [CONTRIBUTING](https://github.com/sorge-it/cdp-browser-installer/blob/main/.github/CONTRIBUTING.md)
describes; a fix is welcome but not required. Report a vulnerability privately, not in a pull request: see the
[security policy](https://github.com/sorge-it/cdp-browser-installer/security/policy).

## License

MIT, see [LICENSE](https://github.com/sorge-it/cdp-browser-installer/blob/main/LICENSE). Chrome
for Testing has its own licenses; the archive of `chrome-headless-shell` contains
`LICENSE.headless_shell`, the archive of `chromedriver` contains `LICENSE.chromedriver`.

## Trademarks

Chrome™ and Chromium™ are trademarks of Google LLC. This project names them only to identify these
browsers: Chrome for Testing, the browser and the ChromeDriver that it installs; the system's
Chromium, which it uses on musl; and the browsers in the comparison. This project is not affiliated
with, endorsed by or sponsored by Google.

## About the author

**Stefan Sorge** is a PHP expert, builder and agentic engineer. He has written PHP for more than 25
years and has built software for over 40 startups. His tools are made for Agentic DX: a developer
and a coding agent read the same output and act on it the same way.

PHP packages:

- [http-server](https://github.com/sorge-it/http-server): an in-process HTTP/1.1 server for Pest
  and PHPUnit browser tests; the page sees the test's `RefreshDatabase` transaction, `actingAs()`
  user and fakes
- [cdp-browser-installer](https://github.com/sorge-it/cdp-browser-installer): an installer for
  chrome-headless-shell and ChromeDriver of the same version from Chrome for Testing, pinned and
  SHA-256-checked
- [safe-unzip](https://github.com/sorge-it/safe-unzip): a ZIP extractor that checks each archive
  against a strict allow list and refuses Zip Slip, overlapping zip bombs and symbolic links
- [phpunit-pest-html-assertions](https://github.com/sorge-it/phpunit-pest-html-assertions): HTML
  assertions for PHPUnit and Pest that check rendered HTML with CSS selectors, never as a string

[GitHub](https://github.com/sorge-it) · [LinkedIn](https://www.linkedin.com/in/stefansorge/)
