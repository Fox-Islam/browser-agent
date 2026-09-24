<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\HeldDecisions;
use Phox\BrowserAgent\Agent\RunState;
use Phox\BrowserAgent\Decision\PlanReading;

require_once __DIR__ . '/../Decision/helpers.php';

function heldOnPage(): array
{
    $state = new RunState(null, "Search\nOpen\nHistory", ['Search', 'Open', 'History'], observed([
        ['node' => 1, 'kind' => 'click', 'label' => 'View history'],
        ['node' => 2, 'kind' => 'click', 'label' => 'Go'],
    ]));
    $held = new HeldDecisions;
    $held->update([0, 1, 2], [
        new PlanReading(0.1, 'WAIT'),
        new PlanReading(0.1),
        new PlanReading(0.1, 'CLICK', 'View history', 'click', 0.9),
    ], []);

    return [$state, $held];
}

it('does not run a later sub-goal held answer before the earlier sub-goals are satisfied', function (): void {
    [$state, $held] = heldOnPage();

    expect($held->reuse($state, [0, 1, 2]))->toBeNull()
        ->and($held->held)->toHaveKey(2);
});

it('runs the held answer once its sub-goal is the first outstanding', function (): void {
    [$state, $held] = heldOnPage();
    $decision = $held->reuse($state, [2]);

    expect($decision->choice)->toBe('e1')
        ->and($decision->reusedFor)->toBe(2)
        ->and($decision->model)->toBe('held')
        ->and($held->held)->not->toHaveKey(2);
});
