<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\Agent;
use Phox\BrowserAgent\Agent\AgentOptions;
use Phox\BrowserAgent\Agent\RunState;
use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\WebSocketConnection;
use Phox\BrowserAgent\Replay\Replayer;
use Phox\BrowserAgent\Replay\Script;
use Tests\Browser\LocalChrome;
use Tests\Fakes\FakeModels;

beforeEach(function (): void {
    $this->chrome = new LocalChrome;
    $this->connection = WebSocketConnection::connect($this->chrome->webSocketUrl, timeout: 10.0);
    $this->tab = BrowserTab::open($this->connection);
});

afterEach(function (): void {
    $this->connection->close();
    $this->chrome->stop();
});

function runReaching(FakeModels $models, array $options, string $fixture): RunState
{
    $agent = Agent::make(test()->tab->session, FakeModels::config(), $models->http, new AgentOptions(...$options));

    return $agent->run(test()->chrome->base . '/' . $fixture);
}

it('clicks a control its row reveals on hover', function (): void {
    $state = runReaching(new FakeModels([['CLICK', 'Remove file'], ['DONE']]), ['goals' => ['Remove notes.txt'], 'readOnly' => false], 'reach.html');

    expect($state->status)->toBe('done')
        ->and(LocalChrome::run($this->tab->session, 'window.removed'))->toBe(1);
});

it('clicks a control out of view inside a scrolling panel', function (): void {
    $state = runReaching(new FakeModels([['CLICK', 'Create folder'], ['DONE']]), ['goals' => ['Create a folder'], 'readOnly' => false], 'reach.html');

    expect($state->status)->toBe('done')
        ->and(LocalChrome::run($this->tab->session, 'window.created'))->toBe(1);
});

it('stops offering a control refused as covered twice', function (): void {
    $models = new FakeModels([['CLICK', 'Go'], ['CLICK', 'Go'], ['DONE']]);

    $state = runReaching($models, ['goals' => ['Press Go'], 'readOnly' => false], 'reach.html');
    $third = $models->decisionRequests()[2]['questions']['click_target']['criteria'];

    expect($state->status)->toBe('done')
        ->and(array_column($state->stale, 'reason'))->each->toStartWith('in the way:')
        ->and(count($state->stale))->toBe(2)
        ->and(array_filter($third, fn (string $d) => str_contains($d, "labelled 'Go'")))->toBe([])
        ->and($state->history)->toBe([]);
});

it('drags an element onto a labelled drop zone, and replays the drag', function (): void {
    $models = new FakeModels([['DRAG', 'Task seven', 'drop' => 'Done column'], ['DONE']]);

    $state = runReaching($models, ['goals' => ['Move task seven to Done'], 'readOnly' => false], 'drag.html');
    $script = Script::of($state);
    LocalChrome::goto($this->tab->session, $this->chrome->base . '/drag.html');
    (new Replayer(fn () => $this->tab))->replayIn($this->tab->session, $script);

    expect($state->status)->toBe('done')
        ->and($state->history[0]->drop)->toBe('Done column')
        ->and($script['steps'][0])->toMatchArray(['kind' => 'drag', 'label' => 'Task seven', 'drop' => 'Done column'])
        ->and(LocalChrome::run($this->tab->session, 'window.dropped'))->toBe(['task-7']);
});

it('drops onto an element whose drop handler only page script can see', function (): void {
    $models = new FakeModels([['DRAG', 'Task seven', 'drop' => 'drop area containing: Start here'], ['DONE']]);

    $state = runReaching($models, ['goals' => ['Put task seven on the canvas'], 'readOnly' => false], 'drag.html');

    expect($state->status)->toBe('done')
        ->and(LocalChrome::run($this->tab->session, 'window.canvasDrop'))->toBe('task-7');
});

it('offers no drag to a read-only run', function (): void {
    $models = new FakeModels([['DONE']]);

    runReaching($models, ['goals' => ['Move task seven to Done']], 'drag.html');
    $questions = $models->decisionRequests()[0]['questions'];

    expect(array_keys($questions['operation']['criteria']))->not->toContain('DRAG')
        ->and($questions)->not->toHaveKey('drop_zone');
});
