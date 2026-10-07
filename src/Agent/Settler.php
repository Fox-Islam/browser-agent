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
    /** Longest the moment given to newly arrived content to go quiet, in seconds. */
    private const float QUIET_AFTER_ARRIVAL = 1.0;

    /**
     * @param  Arrival|null  $arrival  the run's network watch; without one no wait covers fetches
     */
    public function __construct(
        private PageReader $reader,
        private CdpSession $cdp,
        private SettleTimings $timings = new SettleTimings,
        private ?Arrival $arrival = null,
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
     * Waits for an operable page, then for stillness and arrival unless the change from $previous
     * is the kind that does not stream content in. A fingerprint change is no trigger: typing and
     * scrolling move it without the page arriving.
     */
    public function settle(?Observation $previous = null, bool $stabilise = false): Observation
    {
        $started = microtime(true);
        $page = $this->holdStill($previous, $stabilise);

        return $stabilise || $previous === null || self::unsettling($previous, $page) ? $this->arrive($page, $started) : $page;
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
        $started = microtime(true);
        $deadline = $started + $this->timings->timeout;
        $page = $this->reader->read();
        while (! $page?->isOperable() && microtime(true) < $deadline) {
            usleep((int) ($this->timings->poll * 1_000_000));
            $page = $this->reader->read();
        }
        $page ??= $this->observe();

        return $before === null || self::unsettling($before, $page) ? $this->arrive($page, $started) : $page;
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

    private function holdStill(?Observation $previous, bool $stabilise): Observation
    {
        $deadline = microtime(true) + $this->timings->timeout;
        $page = $this->untilOperable($deadline);
        if ($page !== null && ! $stabilise && $previous !== null && ! self::unsettling($previous, $page)) {
            return $page;
        }

        return $this->reader->quietMs() >= $this->timings->stillMs() ? $this->observe() : $this->untilStill($deadline);
    }

    private function untilOperable(float $deadline): ?Observation
    {
        $page = $this->reader->read();
        while (! $page?->isOperable() && microtime(true) < $deadline) {
            usleep((int) ($this->timings->poll * 1_000_000));
            $page = $this->reader->read();
        }

        return $page;
    }

    private function untilStill(float $deadline): Observation
    {
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
     * The page once its fetches have come back and it has stopped showing more loading
     * indicators than its floor, waiting no longer than the arrival limit from $started. What
     * arrives is drawn in more than one pass, so after a wait it is given a moment to go quiet.
     */
    private function arrive(Observation $page, float $started): Observation
    {
        if ($this->arrival === null) {
            return $page;
        }
        $deadline = $started + $this->timings->arrival;
        $floor = $this->arrival->floor($page->url);
        $waited = false;
        while (($page->busy > $floor || $this->arrival->pending() > 0) && microtime(true) < $deadline) {
            usleep((int) ($this->timings->poll * 2 * 1_000_000));
            $page = $this->reader->read() ?? $page;
            $waited = true;
        }
        $this->arrival->raiseFloor($page->url, $page->busy);
        if ($this->arrival->pending() > 0) {
            $this->arrival->forgetOpen();
        }
        if ($waited) {
            $this->reader->holdsStill($this->timings->stillMs(), self::QUIET_AFTER_ARRIVAL);
        }

        return $waited ? $this->reader->read() ?? $page : $page;
    }

    private function observe(): Observation
    {
        return $this->reader->read() ?? throw new RuntimeException('The document has no body to read.');
    }
}
