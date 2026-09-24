<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Cdp\CdpSession;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\PageReader;
use RuntimeException;

/**
 * Observes a page once it can be acted on. The agent observes without waiting for stillness and
 * checks the page again when a decision arrives; a replay has no decision to check, so it waits
 * for stillness. That wait runs inside the page, so on a hosted endpoint, where every look is a
 * round trip, a wait of any length costs one call.
 */
final readonly class Settler
{
    public function __construct(
        private PageReader $reader,
        private CdpSession $cdp,
        private SettleTimings $timings = new SettleTimings,
    ) {}

    /**
     * A navigation, a menu or dialog opening, or a large change in what is on offer.
     */
    public static function unsettling(Observation $previous, Observation $current): bool
    {
        $before = count($previous->actions);
        $after = count($current->actions);

        return $previous->url !== $current->url
            || $current->expandedCount() > $previous->expandedCount()
            || abs($after - $before) >= max(3, intdiv($before, 10));
    }

    /**
     * Waits for an operable page, then for stillness unless the change from $previous is the kind
     * that does not stream content in. A fingerprint change is no trigger: typing and scrolling
     * move it without the page arriving.
     */
    public function settle(?Observation $previous = null, bool $stabilise = false): Observation
    {
        $deadline = microtime(true) + $this->timings->timeout;
        $page = $this->reader->read();
        while (! $page?->isOperable() && microtime(true) < $deadline) {
            usleep((int) ($this->timings->poll * 1_000_000));
            $page = $this->reader->read();
        }
        if ($page !== null && ! $stabilise && $previous !== null && ! self::unsettling($previous, $page)) {
            return $page;
        }
        if ($this->reader->quietMs() >= $this->timings->stillMs()) {
            return $this->observe();
        }
        while (($remaining = $deadline - microtime(true)) > 0) {
            $still = $this->reader->holdsStill($this->timings->stillMs(), $remaining);
            $page = $this->reader->read();
            if ($still && $page?->isOperable()) {
                return $page;
            }
        }

        return $this->observe();
    }

    /**
     * The page as soon as it can be acted on, without waiting for it to stop changing; the agent
     * keeps a decision about it only if the page is unchanged when the answer arrives. After an
     * input it pauses for the page to begin responding; after a wheel scroll it waits for the
     * scroll to land.
     */
    public function readable(?Observation $before = null, ?string $kind = null): Observation
    {
        if ($before !== null && $kind === 'scroll') {
            $this->reader->waitForScroll($before->scrollY, (int) round($this->timings->scroll * 1000));
        } elseif ($before !== null) {
            usleep((int) ($this->timings->afterInput * 1_000_000));
        }
        $deadline = microtime(true) + $this->timings->timeout;
        $page = $this->reader->read();
        while (! $page?->isOperable() && microtime(true) < $deadline) {
            usleep((int) ($this->timings->poll * 1_000_000));
            $page = $this->reader->read();
        }

        return $page ?? $this->observe();
    }

    /**
     * The page after an action taken on $before. A scroll brings in what was below the fold
     * without changing the url or expanding anything, so after a scroll the wait is for stillness.
     */
    public function settleAfter(Observation $before, string $kind): Observation
    {
        usleep((int) ($this->timings->afterInput * 1_000_000));
        $scrolled = $kind === 'scroll';

        return $this->settle($scrolled ? null : $before, stabilise: $scrolled);
    }

    /**
     * Whether the current page is the one observed.
     */
    public function fresh(Observation $page): bool
    {
        return $this->reader->read()?->fingerprint === $page->fingerprint;
    }

    /**
     * Goes to $url and waits for the document to finish loading.
     */
    public function navigate(string $url): void
    {
        $this->cdp->send('Page.navigate', ['url' => $url]);
        $deadline = microtime(true) + $this->timings->navigation;
        do {
            usleep(20_000);
            $state = $this->cdp->send('Runtime.evaluate', ['expression' => 'document.readyState', 'returnByValue' => true]);
        } while (($state['result']['value'] ?? null) !== 'complete' && microtime(true) < $deadline);
    }

    private function observe(): Observation
    {
        return $this->reader->read() ?? throw new RuntimeException('The document has no body to read.');
    }
}
