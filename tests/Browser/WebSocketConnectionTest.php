<?php

declare(strict_types=1);

use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpTimeoutException;
use Phox\BrowserAgent\Cdp\WebSocketConnection;
use Tests\Browser\LocalChrome;

beforeEach(function (): void {
    $this->chrome = new LocalChrome;
    $this->connection = WebSocketConnection::connect($this->chrome->webSocketUrl, timeout: 5.0);
});

afterEach(function (): void {
    $this->connection->close();
    $this->chrome->stop();
});

it('answers hundreds of calls in order on one connection', function (): void {
    $tab = BrowserTab::open($this->connection);
    for ($i = 0; $i < 500; $i++) {
        expect(LocalChrome::run($tab->session, "{$i} * 2"))->toBe($i * 2);
    }
    $tab->close();
});

it('carries a large response in one message', function (): void {
    $tab = BrowserTab::open($this->connection);

    expect(mb_strlen(LocalChrome::run($tab->session, '"x".repeat(20 * 1024 * 1024)')))->toBe(20 * 1024 * 1024);
});

it('fails a call that gets no answer by its deadline and stays usable', function (): void {
    $tab = BrowserTab::open($this->connection);
    $started = microtime(true);

    expect(fn () => $this->connection->call('Runtime.evaluate', ['expression' => 'new Promise(() => {})', 'awaitPromise' => true], $tab->session->sessionId, timeout: 0.5))
        ->toThrow(CdpTimeoutException::class, 'Runtime.evaluate got no answer within 0.5s')
        ->and(microtime(true) - $started)->toBeLessThan(1.5)
        ->and(LocalChrome::run($tab->session, '1 + 1'))->toBe(2);
});

it('fails pending and later calls loudly when the browser goes away', function (): void {
    $tab = BrowserTab::open($this->connection);
    $this->chrome->stop();

    expect(fn () => LocalChrome::run($tab->session, '1'))->toThrow(CdpException::class, 'CDP connection closed')
        ->and(fn () => LocalChrome::run($tab->session, '1'))->toThrow(CdpException::class, 'CDP connection closed');
});

it('reports a protocol error with its code', function (): void {
    expect(fn () => $this->connection->call('No.such'))->toThrow(CdpException::class, "No.such: 'No.such' wasn't found");
});

it('gives each tab its own browser context and disposes it on close', function (): void {
    $first = BrowserTab::open($this->connection);
    $second = BrowserTab::open($this->connection);
    LocalChrome::goto($first->session, "{$this->chrome->base}/names.html");
    LocalChrome::goto($second->session, "{$this->chrome->base}/names.html");
    LocalChrome::run($first->session, 'localStorage.setItem("run", "first")');

    expect(LocalChrome::run($second->session, 'localStorage.getItem("run")'))->toBeNull();
    $first->close();
    $contexts = fn () => array_column($this->connection->call('Target.getTargets')['targetInfos'], 'browserContextId');

    expect($contexts())->not->toContain($first->contextId)
        ->and($contexts())->toContain($second->contextId)
        ->and(fn () => LocalChrome::run($first->session, '1'))->toThrow(CdpException::class);
});

it('times out connecting to an endpoint that never answers the handshake', function (): void {
    $silent = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($silent, false);
    $started = microtime(true);

    expect(fn () => WebSocketConnection::connect("ws://{$address}/", connectTimeout: 0.5))
        ->toThrow(CdpTimeoutException::class)
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
    fclose($silent);
});

it('reports a refused handshake as a CDP error', function (): void {
    expect(fn () => WebSocketConnection::connect(str_replace('http://', 'ws://', $this->chrome->base) . '/names.html', connectTimeout: 5.0))
        ->toThrow(CdpException::class, 'Connecting to the browser failed');
});
