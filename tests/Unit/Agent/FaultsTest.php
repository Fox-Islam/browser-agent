<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\Faults;
use Tests\Fakes\FakeCdpSession;

it('collects console errors, exceptions and failed requests from page events', function (): void {
    $cdp = new FakeCdpSession;
    $faults = new Faults;
    $faults->watch($cdp);
    $cdp->emit('Network.responseReceived', ['type' => 'Document', 'response' => ['url' => 'https://x.test/', 'status' => 200, 'mimeType' => 'text/html; charset=utf-8']]);
    $cdp->emit('Log.entryAdded', ['entry' => ['level' => 'error', 'text' => 'Uncaught thing', 'url' => 'https://x.test/app.js']]);
    $cdp->emit('Log.entryAdded', ['entry' => ['level' => 'info', 'text' => 'hello']]);
    $cdp->emit('Runtime.exceptionThrown', ['exceptionDetails' => ['exception' => ['description' => 'TypeError: x'], 'url' => 'https://x.test/a.js']]);
    $cdp->emit('Network.responseReceived', ['type' => 'Image', 'response' => ['url' => 'https://x.test/a.png', 'status' => 404]]);
    $cdp->emit('Network.loadingFailed', ['errorText' => 'net::ERR_BLOCKED_BY_CLIENT']);

    expect($cdp->methods())->toBe(['Log.enable', 'Runtime.enable', 'Network.enable', 'Page.enable'])
        ->and($faults->diagnosis())->toBe([
            'console' => [['level' => 'error', 'text' => 'Uncaught thing', 'url' => 'https://x.test/app.js']],
            'exceptions' => [['text' => 'TypeError: x', 'url' => 'https://x.test/a.js']],
            'requests' => [['url' => 'https://x.test/a.png', 'status' => 404], ['url' => null, 'status' => null, 'failed' => 'net::ERR_BLOCKED_BY_CLIENT']],
            'document_status' => 200,
            'document_type' => 'text/html',
        ]);
});

it('deduplicates faults and keeps at most a fixed number of each kind', function (): void {
    $faults = new Faults;
    for ($i = 0; $i < 40; $i++) {
        $faults->note('Log.entryAdded', ['entry' => ['level' => 'error', 'text' => 'same']]);
        $faults->note('Network.responseReceived', ['type' => 'Image', 'response' => ['url' => "https://x.test/{$i}.png", 'status' => 404]]);
    }

    expect($faults->diagnosis()['console'])->toHaveCount(1)
        ->and($faults->diagnosis()['requests'])->toHaveCount(Faults::KEPT);
});

it('takes the document status from the first document response only', function (): void {
    $faults = new Faults;
    $faults->note('Network.responseReceived', ['type' => 'Document', 'response' => ['url' => 'https://x.test/', 'status' => 503, 'mimeType' => 'text/html']]);
    $faults->note('Network.responseReceived', ['type' => 'Document', 'response' => ['url' => 'https://x.test/frame', 'status' => 200, 'mimeType' => 'text/html']]);

    expect($faults->documentStatus())->toBe(503);
});
