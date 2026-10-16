<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\AfterAction;
use Phox\BrowserAgent\Agent\Faults;
use Tests\Fakes\FakeCdpSession;

function fetched(FakeCdpSession $cdp, string $id, string $method, string $url, int $status, string $type = 'Fetch'): void
{
    $cdp->emit('Network.requestWillBeSent', ['requestId' => $id, 'type' => $type, 'request' => ['method' => $method, 'url' => $url]]);
    $cdp->emit('Network.responseReceived', ['requestId' => $id, 'type' => $type, 'response' => ['url' => $url, 'status' => $status]]);
}

it("tells a decision of the site's own writes and failures and nothing else", function (): void {
    $cdp = new FakeCdpSession;
    $after = new AfterAction;
    $after->watch($cdp);
    fetched($cdp, '1', 'POST', 'https://api.example.test/save', 500);
    fetched($cdp, '2', 'GET', 'https://example.test/items', 200);
    fetched($cdp, '3', 'GET', 'https://example.test/stock', 404);
    fetched($cdp, '4', 'POST', 'https://analytics.other.test/collect', 500);
    fetched($cdp, '5', 'POST', 'https://example.test/script.js', 500, 'Script');

    expect($after->report('https://shop.example.test/profile'))->toBe(['network_after_last_action' => [
        ['method' => 'POST', 'url' => 'https://api.example.test/save', 'status' => 500],
        ['method' => 'GET', 'url' => 'https://example.test/stock', 'status' => 404],
    ]]);
});

it("tells a decision of the page's console errors and exceptions, and forgets them at the next input", function (): void {
    $cdp = new FakeCdpSession;
    $after = new AfterAction;
    $after->watch($cdp);
    $cdp->emit('Runtime.consoleAPICalled', ['type' => 'error', 'args' => [['type' => 'string', 'value' => 'Profile save failed']]]);
    $cdp->emit('Runtime.consoleAPICalled', ['type' => 'log', 'args' => [['value' => 'hello']]]);
    $cdp->emit('Runtime.exceptionThrown', ['exceptionDetails' => ['exception' => ['description' => 'TypeError: x']]]);
    $told = $after->report('https://example.test/');
    $after->reset();

    expect($told)->toBe(['console_errors_after_last_action' => ['Profile save failed', 'TypeError: x']])
        ->and($after->report('https://example.test/'))->toBe([]);
});

it("reports a page's own console.error as a fault", function (): void {
    $cdp = new FakeCdpSession;
    $faults = new Faults;
    $faults->watch($cdp);
    $cdp->emit('Runtime.consoleAPICalled', ['type' => 'error', 'args' => [['type' => 'string', 'value' => 'Profile save failed']]]);
    $cdp->emit('Runtime.consoleAPICalled', ['type' => 'warning', 'args' => [['value' => 'slow']]]);

    expect($faults->diagnosis()['console'])->toBe([['level' => 'error', 'text' => 'Profile save failed', 'url' => null]]);
});
