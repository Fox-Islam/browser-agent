<?php

declare(strict_types=1);

use Phox\BrowserAgent\Decision\ReadOnlyActions;

require_once __DIR__ . '/helpers.php';

function availability(array $controls): array
{
    $marked = ReadOnlyActions::mark(observed($controls)->actions);

    return array_combine(array_map(fn ($a) => $a->label, $marked), array_map(fn ($a) => $a->available, $marked));
}

it('marks typing, selecting and submitting unavailable and keeps them in the observation', function (): void {
    expect(availability([...formControls(), ['node' => 5, 'kind' => 'click', 'label' => 'About us']]))->toBe([
        'Name' => false,
        'Open Name' => true,
        'Size → Small' => false,
        'Size → Large' => false,
        'Subscribe' => false,
        'Send' => false,
        'About us' => true,
        'Wait for the page to update' => true,
    ]);
});

it('marks a form submitter unavailable whatever its label says', function (): void {
    expect(availability([['node' => 1, 'kind' => 'click', 'label' => 'Go', 'submits' => true], ['node' => 2, 'kind' => 'click', 'label' => 'Stay']]))
        ->toMatchArray(['Go' => false, 'Stay' => true]);
});

it('recognises submitting words at either end of a label, not inside another word', function (string $label, bool $submits): void {
    expect(ReadOnlyActions::submits($label))->toBe($submits);
})->with([
    ['Send', true], ['Send message', true], ['Log in', true], ['Proceed to checkout', true],
    ['Sender details', false], ['Pricing', false], ['Next', true], ['Home', false],
]);
