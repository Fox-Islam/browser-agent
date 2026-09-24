<?php

declare(strict_types=1);

namespace Tests\Browser;

use Phox\BrowserAgent\Cdp\CdpSession;
use RuntimeException;

/**
 * A local headless Chrome and a PHP server for the reader's HTML fixtures, both on 127.0.0.1.
 */
final class LocalChrome
{
    public readonly string $webSocketUrl;

    public readonly string $base;

    /** @var resource */
    private $chrome;

    /** @var resource */
    private $server;

    private string $profile;

    public function __construct()
    {
        $binary = self::binary();
        $this->profile = sys_get_temp_dir() . '/phox-chrome-' . bin2hex(random_bytes(4));
        $this->chrome = self::spawn([$binary, '--headless', '--remote-debugging-port=0', "--user-data-dir={$this->profile}", '--no-first-run', '--no-sandbox', '--hide-scrollbars'], $pipes);
        $this->webSocketUrl = self::waitFor($pipes[2], '/DevTools listening on (ws:\/\/\S+)/');
        $port = self::freePort();
        $this->server = self::spawn([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', dirname(__DIR__, 2) . '/reader/tests/fixtures'], $serverPipes);
        self::waitFor($serverPipes[2], '/Development Server .* started/');
        $this->base = "http://127.0.0.1:{$port}";
    }

    public function __destruct()
    {
        $this->stop();
    }

    public static function available(): bool
    {
        return self::binary(throw: false) !== null;
    }

    /**
     * Navigates and polls document.readyState until the page has loaded.
     */
    public static function goto(CdpSession $session, string $url): void
    {
        $session->send('Page.navigate', ['url' => $url]);
        $deadline = microtime(true) + 10;
        do {
            usleep(20_000);
            $state = $session->send('Runtime.evaluate', ['expression' => 'location.href + " " + document.readyState', 'returnByValue' => true]);
        } while ($state['result']['value'] !== "{$url} complete" && microtime(true) < $deadline);
    }

    public static function run(CdpSession $session, string $expression): mixed
    {
        return $session->send('Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true])['result']['value'] ?? null;
    }

    public function stop(): void
    {
        foreach ([$this->chrome, $this->server] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
        if (is_dir($this->profile)) {
            exec('rm -rf ' . escapeshellarg($this->profile));
        }
    }

    private static function binary(bool $throw = true): ?string
    {
        $candidates = [getenv('CHROME_PATH') ?: null, ...glob(getenv('HOME') . '/.cache/puppeteer/chrome-headless-shell/*/*/chrome-headless-shell') ?: [], '/usr/bin/chromium'];
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_executable($candidate)) {
                return $candidate;
            }
        }
        if ($throw) {
            throw new RuntimeException('No headless Chrome found. Set CHROME_PATH to a Linux Chrome or chrome-headless-shell binary.');
        }

        return null;
    }

    /**
     * @param  list<string>  $command
     * @return resource
     */
    private static function spawn(array $command, ?array &$pipes)
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            throw new RuntimeException("Could not start {$command[0]}");
        }
        stream_set_blocking($pipes[2], false);

        return $process;
    }

    /**
     * @param  resource  $stream
     */
    private static function waitFor($stream, string $pattern): string
    {
        $output = '';
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $output .= (string) fread($stream, 8192);
            if (preg_match($pattern, $output, $match) === 1) {
                return $match[1] ?? $match[0];
            }
            usleep(20_000);
        }

        throw new RuntimeException("Timed out waiting for {$pattern}: {$output}");
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) mb_substr(mb_strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
