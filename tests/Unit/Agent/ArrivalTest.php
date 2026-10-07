<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\Arrival;
use Phox\BrowserAgent\Agent\Settler;
use Phox\BrowserAgent\Agent\SettleTimings;
use Phox\BrowserAgent\Reader\PageReader;
use Tests\Fakes\FakeCdpSession;

/**
 * A page whose reads report the busy counts in $busy in turn, the last one repeating, and that
 * is always quiet. $onRead runs on every read, while a call is waiting, as events do.
 *
 * @param  list<int>  $busy
 */
function arrivingPage(array $busy, ?Closure $onRead = null): FakeCdpSession
{
    $reads = 0;

    return (new FakeCdpSession)
        ->on('Page.getFrameTree', fn () => ['frameTree' => ['frame' => ['id' => 'F']]])
        ->on('Page.createIsolatedWorld', fn () => ['executionContextId' => 1])
        ->on('Runtime.evaluate', function (array $params) use (&$reads, $busy, $onRead): array {
            $expression = $params['expression'];
            if (str_starts_with($expression, 'pageReader.read(')) {
                if ($onRead !== null) {
                    $onRead($reads);
                }

                return ['result' => ['value' => arrivingObservation($busy[min($reads++, count($busy) - 1)])]];
            }

            return ['result' => ['value' => str_starts_with($expression, 'pageReader.still(') ? ['still' => true] : 1000]];
        });
}

function arrivingObservation(int $busy): array
{
    return [
        'url' => 'https://app.test/projects', 'title' => 'Projects', 'viewport' => ['w' => 1120, 'h' => 780],
        'scroll' => ['y' => 0, 'height' => 780, 'view' => 780], 'text' => 'Projects', 'omitted_actions' => 0,
        'actions' => [['id' => 'e1', 'node' => 1, 'kind' => 'click', 'role' => 'button', 'label' => 'Settings', 'rect' => ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]]],
        'page_key' => 'k', 'guards' => [], 'busy' => $busy,
    ];
}

function arrivalSettler(FakeCdpSession $cdp, Arrival $arrival, float $arrivalSeconds = 2.0): Settler
{
    return new Settler(new PageReader($cdp), $cdp, new SettleTimings(poll: 0.005, arrival: $arrivalSeconds), $arrival);
}

it('waits for a page still showing loading indicators to stop', function (): void {
    $cdp = arrivingPage([2, 2, 0]);
    $arrival = new Arrival;
    $arrival->watch($cdp);

    $page = arrivalSettler($cdp, $arrival)->readable();

    expect($page->busy)->toBe(0)
        ->and($cdp->methods())->toContain('Network.enable');
});

it('waits for fetches the page started to come back', function (): void {
    $arrival = new Arrival;
    $cdp = arrivingPage([0], function (int $read) use ($arrival): void {
        if ($read === 0) {
            $arrival->note('Network.requestWillBeSent', ['requestId' => 'r1', 'type' => 'Fetch']);
        }
        if ($read === 3) {
            $arrival->note('Network.loadingFinished', ['requestId' => 'r1']);
        }
    });

    arrivalSettler($cdp, $arrival)->readable();

    expect($arrival->pending())->toBe(0)
        ->and(count(array_filter($cdp->calls, fn ($c) => str_starts_with($c['params']['expression'] ?? '', 'pageReader.read('))))->toBeGreaterThanOrEqual(4);
});

it('does not wait after an input that changed little', function (): void {
    $cdp = arrivingPage([3]);
    $arrival = new Arrival;
    $settler = arrivalSettler($cdp, $arrival, arrivalSeconds: 0.05);
    $before = $settler->readable();
    $cdp->calls = [];

    $settler->readable($before, 'click');

    expect(count(array_filter($cdp->calls, fn ($c) => str_starts_with($c['params']['expression'] ?? '', 'pageReader.read('))))->toBe(1);
});

it('reads a page whose loading indicator never goes away once the arrival limit is reached, and keeps that as its floor', function (): void {
    $cdp = arrivingPage([1]);
    $arrival = new Arrival;
    $settler = arrivalSettler($cdp, $arrival, arrivalSeconds: 0.05);

    $started = microtime(true);
    $settler->readable();
    $first = microtime(true) - $started;
    $started = microtime(true);
    $settler->readable();

    expect($arrival->floor('https://app.test/projects'))->toBe(1)
        ->and($first)->toBeGreaterThanOrEqual(0.05)
        ->and(microtime(true) - $started)->toBeLessThan(0.05);
});

it('stops counting a fetch open longer than the long-request limit', function (): void {
    $arrival = new Arrival(longRequest: 0.01);

    $arrival->note('Network.requestWillBeSent', ['requestId' => 'stream', 'type' => 'Fetch']);
    $open = $arrival->pending();
    usleep(20_000);

    expect($open)->toBe(1)->and($arrival->pending())->toBe(0);
});

it('counts only fetches, and forgets a failed one', function (): void {
    $arrival = new Arrival;

    $arrival->note('Network.requestWillBeSent', ['requestId' => 'img', 'type' => 'Image']);
    $arrival->note('Network.requestWillBeSent', ['requestId' => 'x', 'type' => 'XHR']);
    $counted = $arrival->pending();
    $arrival->note('Network.loadingFailed', ['requestId' => 'x']);

    expect($counted)->toBe(1)->and($arrival->pending())->toBe(0);
});
