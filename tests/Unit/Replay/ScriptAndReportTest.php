<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\RunState;
use Phox\BrowserAgent\Agent\Step;
use Phox\BrowserAgent\Decision\Decision;
use Phox\BrowserAgent\Replay\Report;
use Phox\BrowserAgent\Replay\Script;

require_once __DIR__ . '/../Decision/helpers.php';

function ranState(int $steps = 3, ?string $url = 'https://example.test/form'): RunState
{
    $state = new RunState($url, 'Sign up', ['Sign up'], observed(formControls(), text: str_repeat('Long page text. ', 2000)));
    for ($n = 1; $n <= $steps; $n++) {
        $step = new Step($n, $n === 1 ? 'Name' : "Button {$n} with a label that goes on and on and on", $n === 1 ? 'fill' : 'click', "e{$n}", 'CLICK', 0.9, 0.8, $n === 1 ? 'Fox' : null, $n < 3 ? 'https://example.test/form' : 'https://example.test/thanks?' . str_repeat('q=1&', 50), $n * 100, node: $n);
        $step->pageChanged = $n !== 2;
        $step->scroll = ['y' => 0, 'height' => $n < 3 ? 2000 : 900, 'view' => 780];
        $state->history[] = $step;
    }
    $state->decisions[] = new Decision('DONE', 'DONE', null, 0.9, ['DONE' => 0.9], ['DONE' => 0.91234, 'WAIT' => 0.08766]);
    $state->status = 'done';

    return $state;
}

it('names controls by label and kind and keeps no element ids', function (): void {
    $script = Script::of(ranState(), 'signup');

    expect($script)->toBe([
        'version' => 1, 'name' => 'signup', 'url' => 'https://example.test/form', 'goal' => 'Sign up',
        'steps' => [
            ['kind' => 'fill', 'label' => 'Name', 'text' => 'Fox'],
            ['kind' => 'click', 'label' => 'Button 2 with a label that goes on and on and on'],
            ['kind' => 'click', 'label' => 'Button 3 with a label that goes on and on and on'],
        ],
    ])->and(json_encode($script))->not->toContain('"e1"');
});

it('falls back to the first page a run acted on for its url', function (): void {
    expect(Script::of(ranState(url: null))['url'])->toBe('https://example.test/form');
});

it('refuses a script from another version, without a url or without steps', function (array $script, string $message): void {
    expect(fn () => Script::validate($script))->toThrow(InvalidArgumentException::class, $message);
})->with([
    [['version' => 2, 'url' => 'https://x.test', 'steps' => []], 'Script version 2 is not 1'],
    [['version' => 1, 'steps' => []], 'Script has no url to start from'],
    [['version' => 1, 'url' => 'https://x.test'], 'Script has no steps'],
]);

it('knows a script that types, selects or submits cannot be repeated safely', function (array $steps, bool $mutates): void {
    expect(Script::mutates(['steps' => $steps]))->toBe($mutates);
})->with([
    [[['kind' => 'click', 'label' => 'About'], ['kind' => 'scroll', 'label' => 'Scroll down']], false],
    [[['kind' => 'fill', 'label' => 'Name', 'text' => 'Fox']], true],
    [[['kind' => 'select', 'label' => 'Size → Large']], true],
    [[['kind' => 'click', 'label' => 'Send message']], true],
    [[['kind' => 'click', 'label' => 'Go', 'submits' => true]], true],
]);

it('reports labels, positions and operations but no page content or element ids', function (): void {
    $report = Report::of(ranState());
    $json = json_encode($report);

    expect($report['steps'][0])->toBe(['kind' => 'fill', 'node' => 1, 'label' => 'Name', 'changed' => true, 'y' => 0, 'text' => 'Fox', 'height' => 2000])
        ->and($report['steps'][1])->toBe(['kind' => 'click', 'node' => 2, 'label' => 'Button 2 with a label that goes…', 'changed' => false, 'y' => 0])
        ->and($report['operations'])->toBe(['DONE' => 0.912, 'WAIT' => 0.088])
        ->and($report['final']['controls'])->toBe([
            ['node' => 1, 'label' => 'Name'], ['node' => 2, 'label' => 'Size → Small'],
            ['node' => 2, 'label' => 'Size → Large'], ['node' => 3, 'label' => 'Subscribe'], ['node' => 4, 'label' => 'Send'],
        ])
        ->and($json)->not->toContain('Long page text')
        ->and($json)->not->toContain('"e1"');
});

it('carries a url and a height only when they change', function (): void {
    $steps = Report::of(ranState())['steps'];

    expect($steps[0])->not->toHaveKey('url')
        ->and($steps[1])->not->toHaveKey('height')
        ->and(mb_strlen($steps[2]['url']))->toBe(Report::URL_LENGTH)
        ->and($steps[2]['height'])->toBe(900);
});

it('stays small however large the page is', function (): void {
    $state = ranState(steps: 10);
    $state->page = observed(array_map(fn ($n) => ['node' => $n, 'kind' => 'click', 'label' => str_repeat("Control {$n} ", 20)], range(1, 200)));

    expect(Report::of($state)['final']['controls'])->toHaveCount(Report::CONTROLS)
        ->and(mb_strlen(json_encode(Report::of($state))))->toBeLessThan(4096);
});
