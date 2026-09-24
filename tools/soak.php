<?php

declare(strict_types=1);

/*
 * Long run of the CDP transport and the page reader against a real browser endpoint, for
 * example Cloudflare Browser Run. It costs browser time, so it is a manual tool, not a test.
 *
 *   CDP_URL=wss://... CDP_AUTHORIZATION="Bearer ..." php tools/soak.php [calls] [url]
 *
 * Every call has its own deadline; the run reports latency percentiles and every failure, and
 * exits non-zero when any call failed.
 */

use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\WebSocketConnection;
use Phox\BrowserAgent\Reader\PageReader;

require __DIR__ . '/../vendor/autoload.php';

$endpoint = getenv('CDP_URL') ?: throw new RuntimeException('Set CDP_URL to the browser WebSocket URL');
$headers = getenv('CDP_AUTHORIZATION') ? ['Authorization' => getenv('CDP_AUTHORIZATION')] : [];
$calls = (int) ($argv[1] ?? 300);
$page = $argv[2] ?? 'https://en.wikipedia.org/wiki/Main_Page';

$connection = WebSocketConnection::connect($endpoint, $headers, timeout: 20.0);
$tab = BrowserTab::open($connection);
$tab->session->send('Page.navigate', ['url' => $page]);
sleep(3);
$reader = new PageReader($tab->session);

$steps = [
    'read' => fn () => $reader->read(),
    'pageKey' => fn () => $reader->pageKey(),
    'large' => fn () => $tab->session->send('Runtime.evaluate', ['expression' => '"x".repeat(4 * 1024 * 1024)', 'returnByValue' => true]),
    'screenshot' => fn () => $tab->session->send('Page.captureScreenshot', ['format' => 'png']),
];
$timings = [];
$failures = [];
for ($i = 0; $i < $calls; $i++) {
    $name = array_keys($steps)[$i % count($steps)];
    $started = hrtime(true);
    try {
        $steps[$name]();
        $timings[$name][] = (hrtime(true) - $started) / 1e6;
    } catch (CdpException $e) {
        $failures[] = sprintf('#%d %s after %.0fms: %s: %s', $i, $name, (hrtime(true) - $started) / 1e6, $e::class, $e->getMessage());
    }
}
$tab->close();
$connection->close();

foreach ($timings as $name => $samples) {
    sort($samples);
    $at = fn (float $q) => $samples[(int) floor($q * (count($samples) - 1))];
    printf("%-10s n=%-4d p50=%6.1fms p95=%6.1fms max=%6.1fms\n", $name, count($samples), $at(0.5), $at(0.95), end($samples));
}
printf("%d of %d calls failed\n", count($failures), $calls);
echo implode("\n", $failures), $failures === [] ? '' : "\n";
exit($failures === [] ? 0 : 1);
