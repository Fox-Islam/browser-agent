<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Cdp\CdpSession;

/**
 * What the last input caused, for the next decision: the site's own fetches that wrote something
 * or failed, and the console errors and uncaught exceptions. A save the page reports as done
 * while its request failed, or while it logged an error, is then not taken for success. Collected
 * from CDP events as they arrive, with no round trip per step; {@see Faults} enables the domains.
 */
final class AfterAction
{
    /** How much of what followed an input a decision is told about. */
    public const int KEPT = 5;

    private const int TEXT_LENGTH = 200;

    private const array FETCHES = ['XHR', 'Fetch'];

    private const array READS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var array<string, string> request id => method */
    private array $sent = [];

    /** @var list<array{method: string|null, url: string, status: int}> */
    private array $requests = [];

    /** @var list<string> */
    private array $console = [];

    public function watch(CdpSession $cdp): void
    {
        $cdp->listen($this->note(...));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function note(string $method, array $params): void
    {
        $fetch = in_array($params['type'] ?? null, self::FETCHES, true);
        match (true) {
            $method === 'Network.requestWillBeSent' && $fetch => $this->sent[(string) ($params['requestId'] ?? '')] = (string) ($params['request']['method'] ?? ''),
            $method === 'Network.responseReceived' && $fetch => $this->requests[] = [
                'method' => $this->sent[(string) ($params['requestId'] ?? '')] ?? null,
                'url' => mb_substr((string) ($params['response']['url'] ?? ''), 0, self::TEXT_LENGTH),
                'status' => (int) ($params['response']['status'] ?? 0),
            ],
            $method === 'Runtime.consoleAPICalled' && ($params['type'] ?? null) === 'error' => $this->console[] = mb_substr(Faults::consoleText($params['args'] ?? []), 0, self::TEXT_LENGTH),
            $method === 'Runtime.exceptionThrown' => $this->console[] = mb_substr((string) ($params['exceptionDetails']['exception']['description'] ?? $params['exceptionDetails']['text'] ?? ''), 0, self::TEXT_LENGTH),
            default => null,
        };
    }

    /**
     * Forget what came before an input, which followed the one before it.
     */
    public function reset(): void
    {
        $this->sent = $this->requests = $this->console = [];
    }

    /**
     * The state entries a decision on the page at $url is sent; empty when nothing followed. A
     * fetch to another site is left out: analytics and adverts make them on every page, and a
     * decision told about them reads a page that works as one that failed.
     *
     * @return array<string, list<mixed>>
     */
    public function report(string $url): array
    {
        $site = self::site($url);
        $requests = array_values(array_filter(
            $this->requests,
            fn (array $r) => self::site($r['url']) === $site && (! in_array($r['method'], [null, ...self::READS], true) || $r['status'] >= 400),
        ));

        return array_filter([
            'network_after_last_action' => array_slice($requests, -self::KEPT),
            'console_errors_after_last_action' => array_slice($this->console, -self::KEPT),
        ]);
    }

    /**
     * The last two labels of a host, so a site's api. and www. subdomains are the same site.
     */
    private static function site(string $url): string
    {
        return implode('.', array_slice(explode('.', (string) parse_url($url, PHP_URL_HOST)), -2));
    }
}
