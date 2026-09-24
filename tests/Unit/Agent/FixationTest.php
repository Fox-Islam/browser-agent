<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\Fixation;
use Phox\BrowserAgent\Agent\Step;

function stepOn(string $label, int $node, ?bool $changed, ?string $result, string $kind = 'click'): Step
{
    static $n = 0;
    $step = new Step(++$n, $label, $kind, 'e1', 'CLICK', 0.9, 0.9, null, 'https://x.test/', 0, node: $node);
    $step->pageChanged = $changed;
    $step->result = $result;

    return $step;
}

it('counts a control that left the page as it did before, even though the page changed', function (): void {
    expect(Fixation::of([stepOn('Send', 4, true, 'after-send'), stepOn('Send', 4, true, 'after-send')]))->toBe([['Send', 'click']]);
});

it('counts a control that moved nothing twice', function (): void {
    expect(Fixation::of([stepOn('Open Return', 2, false, 'a'), stepOn('Next', 3, true, 'b'), stepOn('Open Return', 2, false, 'b')]))->toBe([['Open Return', 'click']]);
});

it('lets a control repeat while each use leaves the page somewhere new', function (): void {
    expect(Fixation::of([stepOn('Load more', 9, true, 'page-2'), stepOn('Load more', 9, true, 'page-3')]))->toBe([]);
});

it('keeps controls with the same label on different nodes apart', function (): void {
    expect(Fixation::of([stepOn('Delete', 5, true, 'r'), stepOn('Delete', 6, true, 'r')]))->toBe([]);
});

it('keeps one ineffective retry and never counts a wait', function (): void {
    expect(Fixation::of([stepOn('Go', 1, false, 'a')]))->toBe([])
        ->and(Fixation::of([stepOn('Wait', 0, false, 'a', 'wait'), stepOn('Wait', 0, false, 'a', 'wait')]))->toBe([]);
});

it('looks only at the last few steps', function (): void {
    expect(Fixation::of([stepOn('Go', 1, false, 'a'), stepOn('Go', 1, false, 'a'), stepOn('A', 2, true, 'b'), stepOn('B', 3, true, 'c'), stepOn('C', 4, true, 'd')]))->toBe([]);
});
