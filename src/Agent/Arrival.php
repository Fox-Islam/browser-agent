<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpSession;

/**
 * Whether a page is still arriving: the fetches it has started and not finished, from network
 * events as they come, and per address the loading indicators that never go away. A single-page
 * app paints its shell, holds still and is operable well before the list it was opened for comes
 * back, so stillness alone hands a decision a page without its content.
 */
final class Arrival
{
    /** Requests a page draws from. Documents, scripts and images are covered by load and paint. */
    private const array FETCHES = ['XHR', 'Fetch'];

    /** @var array<string, float> request id => when it started */
    private array $open = [];

    /** @var array<string, int> url => loading indicators that outlasted a whole wait */
    private array $floors = [];

    public function __construct(private readonly float $longRequest = 10.0) {}

    public function watch(CdpSession $cdp): void
    {
        $cdp->listen($this->note(...));
        try {
            $cdp->send('Network.enable');
        } catch (CdpException) {
            // A target that will not report its network has no fetches to wait for.
        }
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function note(string $method, array $params): void
    {
        $id = (string) ($params['requestId'] ?? '');
        if ($method === 'Network.requestWillBeSent' && in_array($params['type'] ?? null, self::FETCHES, true)) {
            $this->open[$id] = microtime(true);
        } elseif ($method === 'Network.loadingFinished' || $method === 'Network.loadingFailed') {
            unset($this->open[$id]);
        }
    }

    /**
     * Fetches still out. One open longer than the long-request limit is a stream, a long poll or
     * a request that hung, and nothing on the page waits on it.
     */
    public function pending(): int
    {
        $now = microtime(true);

        return count(array_filter($this->open, fn (float $began) => $now - $began < $this->longRequest));
    }

    /**
     * Forgets the fetches still open, so a request that outlasted one wait does not hold up the
     * next.
     */
    public function forgetOpen(): void
    {
        $this->open = [];
    }

    public function floor(string $url): int
    {
        return $this->floors[$url] ?? 0;
    }

    /**
     * Records the loading indicators a page still shows after a whole wait: a progress bar or a
     * spinner that is part of the page. Later waits on the same address wait only for more.
     */
    public function raiseFloor(string $url, int $busy): void
    {
        $this->floors[$url] = max($this->floor($url), $busy);
    }
}
