<?php

declare(strict_types=1);

namespace SorgeIt\CdpBrowserInstaller\Tests;

use RuntimeException;
use SorgeIt\CdpBrowserInstaller\CommandLine;
use SorgeIt\CdpBrowserInstaller\Download;
use SorgeIt\CdpBrowserInstaller\Platform;

/**
 * Fake tools in PHP that record each call. `curl` serves archives from a directory. The
 * `PATH` holds no directory of the system, so a tool of the host, such as `apk` on Alpine, never
 * runs.
 */
final readonly class FakeTools
{
    /**
     * The real tools the installer runs: `rm` clears the unpack directory, safe-unzip runs the
     * other two.
     */
    private const array SYSTEM = ['rm', 'unzip', 'zipinfo'];

    public function __construct(private string $root)
    {
        mkdir($root.'/bin', 0o755, true);
        mkdir($root.'/php');
        mkdir($root.'/system');
        mkdir($root.'/served');

        foreach (self::SYSTEM as $tool) {
            symlink($this->system($tool), $root.'/system/'.$tool);
        }

        $this->add('curl', sprintf(<<<'PHP'
            $source = %s;
            $option = array_search('--output', $argv, true);
            if ($option === false) {
                fwrite(STDERR, "The fake curl needs --output.\n");
                exit(2);
            }
            $output = $argv[$option + 1];
            $url = $argv[$argc - 1];
            $served = $root.'/served/'.(str_starts_with($url, $source) ? substr($url, strlen($source)) : $url);
            $status = is_file($root.'/status') ? (string) file_get_contents($root.'/status') : '200';
            if ($status === 'fail') {
                fwrite(STDERR, "curl: (6) Could not resolve host\n");
                exit(6);
            }
            if ($status === '200' && is_file($served)) {
                copy($served, $output);
            } else {
                $status = $status === '200' ? '404' : $status;
                file_put_contents($output, 'page');
            }
            $dump = array_search('--dump-header', $argv, true);
            if ($dump !== false) {
                // As Google Cloud Storage sends them, with the MD5 of the file that curl wrote.
                $platform = $root.'/headers-'.(explode('/', substr($url, strlen($source)))[1] ?? '');
                $headers = is_file($platform) ? $platform : $root.'/headers';
                $headers = is_file($headers) ? (string) file_get_contents($headers) : "HTTP/2 {status}\r\ncontent-type: application/zip\r\nx-goog-hash: crc32c=le6WFw==\r\nx-goog-hash: md5={md5}\r\n\r\n";
                file_put_contents($argv[$dump + 1], strtr($headers, ['{status}' => $status, '{md5}' => base64_encode(md5_file($output, true))]));
            }
            if (is_file($root.'/delay')) {
                usleep(1000 * (int) file_get_contents($root.'/delay'));
            }
            echo $status;
            PHP, var_export(Download::SOURCE, true)));
        $this->packageManager('apt-get', 'echo "apt-get {$argv[1]}\n";');
    }

    /**
     * A package manager first in `PATH`. It also records the environment of its last call.
     *
     * @param  'apk'|'apt-get'  $name
     * @return string its absolute path
     */
    public function packageManager(string $name, string $php = ''): string
    {
        return $this->add($name, sprintf("file_put_contents(\$root.'/%s.environment', serialize(getenv()));\n%s", $name, $php));
    }

    /**
     * @return array<string, string> the environment of the last call of the package manager, without what `sh` and macOS add
     */
    public function environment(string $tool): array
    {
        $environment = unserialize((string) file_get_contents($this->root.'/'.$tool.'.environment'));
        assert(is_array($environment));
        /** @var array<string, string> $environment */
        $environment = array_diff_key($environment, array_flip(['PWD', 'OLDPWD', 'SHLVL', '_', '__CF_USER_TEXT_ENCODING']));
        ksort($environment);

        return $environment;
    }

    /**
     * The system's browser, as `apk add chromium` brings it: it prints a version, or its
     * arguments.
     *
     * @return string its absolute path
     */
    public function browser(): string
    {
        return $this->add('chromium', <<<'PHP'
            echo ($argv[1] ?? '') === '--version' ? "Chromium 140.0.7339.80 Alpine Linux\n" : 'chromium '.implode(' ', array_slice($argv, 1))."\n";
            PHP);
    }

    /**
     * Puts a tool first in `PATH`. The PHP body gets `$argv`, and `$root` for the files of the
     * fakes.
     *
     * @return string its absolute path
     */
    public function add(string $name, string $php): string
    {
        return $this->write($name, $this->root.'/bin/'.$name, $php);
    }

    /**
     * Puts a real tool of the installer first in `PATH`, with PHP code before and after it.
     *
     * @param  'rm'|'unzip'|'zipinfo'  $tool
     */
    public function around(string $tool, string $before, string $after = ''): void
    {
        $this->add($tool, sprintf('%s $process = proc_open([$root."/system/%s", ...array_slice($argv, 1)], [1 => STDOUT, 2 => STDERR], $pipes); $code = proc_close($process); %s exit($code);', $before, $tool, $after));
    }

    public function serve(string $version, Platform $platform, string $archive): void
    {
        $directory = $this->root.'/served/'.$version.'/'.$platform->value;

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        copy($archive, $directory.'/'.$platform->directory().'.zip');
    }

    /**
     * @param  int|'fail'  $status  `fail` makes curl itself fail
     */
    public function respondWith(int|string $status): void
    {
        file_put_contents($this->root.'/status', (string) $status);
    }

    /**
     * The headers that curl writes for `--dump-header`. `{status}` becomes the status, `{md5}` the
     * MD5 of the file that curl wrote, in base64.
     *
     * @param  Platform|null  $platform  only for the archive of this platform; `null` for each
     */
    public function sendHeaders(string $headers, ?Platform $platform = null): void
    {
        file_put_contents($this->root.'/headers'.($platform instanceof Platform ? '-'.$platform->value : ''), $headers);
    }

    /**
     * Makes curl wait after it wrote the file and before it answers, as a slow download does. Each
     * call is recorded first, so a test sees that curl runs. The time is read at each call.
     */
    public function delay(int $milliseconds): void
    {
        file_put_contents($this->root.'/delay', (string) $milliseconds);
    }

    /**
     * @return list<string> the arguments of each call of the tool, joined by spaces
     */
    public function calls(string $tool): array
    {
        $file = $this->root.'/'.$tool.'.calls';

        return is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) ?: [] : [];
    }

    /**
     * @param  bool  $system  whether the real tools of the installer follow the fakes in `PATH`
     * @param  array<string, string>  $environment  more variables for each process
     */
    public function commandLine(bool $system = true, array $environment = [], int $timeout = 60): CommandLine
    {
        return new CommandLine(['PATH' => $this->root.'/bin'.($system ? ':'.$this->root.'/system' : '')] + $environment, $timeout);
    }

    private function write(string $name, string $file, string $php): string
    {
        $script = $this->root.'/php/'.$name.'.php';
        $record = sprintf("file_put_contents(\$root.'/%s.calls', implode(' ', array_slice(\$argv, 1)).\"\\n\", FILE_APPEND);", $name);
        file_put_contents($script, sprintf("<?php\n\n\$root = %s;\n%s\n%s\n", var_export($this->root, true), $record, $php));

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        // Not `#!` with PHP_BINARY: the kernel ends the interpreter of a `#!` line at the first space.
        file_put_contents($file, sprintf("#!/bin/sh\nexec %s %s \"\$@\"\n", $this->quote(PHP_BINARY), $this->quote($script)));
        chmod($file, 0o755);

        return $file;
    }

    /**
     * A word for `sh`: single quotes, and each single quote as `'\''`.
     */
    private function quote(string $word): string
    {
        return "'".str_replace("'", "'\\''", $word)."'";
    }

    private function system(string $tool): string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if (str_starts_with($directory, '/') && is_file($directory.'/'.$tool) && is_executable($directory.'/'.$tool)) {
                return $directory.'/'.$tool;
            }
        }

        throw new RuntimeException(sprintf('The tests need `%s` in PATH.', $tool));
    }
}
