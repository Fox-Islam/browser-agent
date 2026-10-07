<?php

declare(strict_types=1);

use Phox\BrowserAgent\Decision\ActionSpace;
use Phox\BrowserAgent\Decision\Decider;
use Phox\BrowserAgent\Decision\ModelClient;
use Phox\BrowserAgent\Decision\Prompts;
use Phox\BrowserAgent\Decision\Questions;
use Phox\BrowserAgent\Decision\ReadOnlyActions;
use Phox\BrowserAgent\Reader\Action;
use Tests\Fakes\FakeModels;

require_once __DIR__ . '/helpers.php';

function boardControls(): array
{
    return [
        ['node' => 1, 'kind' => 'drag', 'role' => 'generic', 'label' => 'Task seven'],
        ['node' => 2, 'kind' => 'drop', 'role' => 'region', 'label' => 'Done column'],
        ['node' => 3, 'kind' => 'drop', 'role' => 'region', 'label' => 'Archive'],
        ['node' => 4, 'kind' => 'click', 'label' => 'Settings'],
        ['node' => 5, 'kind' => 'click', 'label' => 'Help'],
    ];
}

function dragDecider(FakeModels $models): Decider
{
    return new Decider(new ModelClient($models->http), FakeModels::config());
}

it('offers DRAG with a drop-zone head, and names the zone the model chose', function (): void {
    $models = new FakeModels([['DRAG', 'Task seven', 'drop' => 'Archive']]);

    $decision = dragDecider($models)->choose(observed(boardControls()), 'Archive task seven', []);
    $questions = $models->decisionRequests()[0]['questions'];

    expect($questions['operation']['criteria'])->toHaveKey('DRAG')
        ->and($questions['operation']['criteria']['DRAG'])->toBe(Prompts::OPERATION_DRAG)
        ->and($questions['drop_zone']['instructions'])->toContain(Prompts::DROP)
        ->and(array_keys($questions['drop_zone']['criteria']))->toBe([1, 2])
        ->and([$decision->choice, $decision->operation, $decision->drop])->toBe(['e1', 'DRAG', 'e3']);
});

it('leaves drop zones out of the element table and the operations', function (): void {
    $space = ActionSpace::of(observed(boardControls())->actions);

    expect(array_column($space->elements, 'label'))->toBe(['Task seven', 'Settings', 'Help'])
        ->and(array_keys($space->zones))->toBe([1, 2])
        ->and($space->controls)->not->toHaveKey('E2');
});

it('offers no drag when nothing can be dropped on', function (): void {
    $models = new FakeModels([['CLICK', 'Settings']]);
    $controls = array_values(array_filter(boardControls(), fn (array $c) => $c['kind'] !== 'drop'));

    dragDecider($models)->choose(observed($controls), 'Open settings', []);
    $questions = $models->decisionRequests()[0]['questions'];

    expect($questions['operation']['criteria'])->not->toHaveKey('DRAG')
        ->and($questions)->not->toHaveKey('drop_zone');
});

it('withholds drags and drop zones from a read-only run', function (): void {
    $marked = ReadOnlyActions::mark(observed(boardControls())->actions);
    $space = ActionSpace::of($marked);

    expect($space->targets)->not->toHaveKey('DRAG')
        ->and($space->zones)->toBe([]);
});

it('tells the model about context, hover and controls out of view in a panel', function (): void {
    $action = new Action('e1', 'click', 'Open', node: 1, role: 'button', hover: true, offscreen: 'panel', context: 'Example project');

    expect(Questions::describe('1', $action))
        ->toBe("Element [1], labelled 'Open', a button, in 'Example project', shown when hovered, out of view inside a scrolling panel.");
});
