<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

/**
 * How long settling waits. The defaults are for a hosted endpoint, where every look is a network
 * round trip and waiting is bounded harder.
 */
final readonly class SettleTimings
{
    /**
     * @param  float  $timeout  longest a settle may take, in seconds
     * @param  float  $still  how long the document must go unchanged to count as still. A duration
     *                        instead of a number of reads, so how often it is checked does not
     *                        change what it means.
     * @param  float  $poll  how often to look for an operable page
     * @param  float  $restless  how long a page may keep changing under a decision before it is
     *                           acted on anyway: a clock or a progress figure never holds still
     * @param  float  $navigation  longest a navigation may take to finish loading
     * @param  float  $afterInput  pause before observing the result of an input: about two
     *                             animation frames, so an opening menu has begun changing the page
     *                             by the time it is looked at
     * @param  float  $scroll  longest to wait for a wheel scroll to move the page and stop
     */
    public function __construct(
        public float $timeout = 1.5,
        public float $still = 0.3,
        public float $poll = 0.05,
        public float $restless = 1.5,
        public float $navigation = 15.0,
        public float $afterInput = 0.05,
        public float $scroll = 0.3,
    ) {}

    public function stillMs(): int
    {
        return (int) round($this->still * 1000);
    }
}
