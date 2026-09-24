<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Agent\Step;
use Phox\BrowserAgent\Reader\Observation;

/**
 * Whether a page has more to show below the viewport.
 */
final class Scrolling
{
    /**
     * How much of the page lies below the viewport, as a fraction of its height. Zero when the
     * page does not report a height, so it is never treated as having more to show.
     */
    public static function unseen(Observation $page): float
    {
        return $page->scrollHeight > 0
            ? max(0, $page->scrollHeight - ($page->scrollY + $page->viewportHeight)) / $page->scrollHeight
            : 0.0;
    }

    /**
     * Whether going further down is worth more than giving up. A long page hides its content
     * instead of lacking it, so BLOCKED from the top establishes nothing. What ends that is a
     * scroll down that moved nothing. The remaining fraction cannot be the evidence: a page that
     * loads as it is scrolled grows as fast as it is read.
     *
     * @param  list<Step>  $history
     */
    public static function stillHelps(Observation $page, array $history): bool
    {
        $canScroll = array_filter($page->actions, fn ($a) => $a->kind === 'scroll' && (int) $a->delta > 0) !== [];
        $downs = array_values(array_filter($history, fn (Step $s) => $s->isScrollDown()));

        return $canScroll && ($downs === [] || end($downs)->pageChanged !== false);
    }
}
