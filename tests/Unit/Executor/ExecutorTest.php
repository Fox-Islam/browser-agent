<?php

declare(strict_types=1);

use Phox\BrowserAgent\Executor\Execution;
use Phox\BrowserAgent\Executor\ExecutionStatus;
use Phox\BrowserAgent\Executor\Executor;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReadingMode;
use Phox\BrowserAgent\Reader\Rect;
use Tests\Fakes\FakeCdpSession;

/**
 * A page whose reader answers pageKey, guard and blocker from $page. setValue holds what it was
 * given unless $page names what it holds instead.
 */
function executorPage(array $page = []): FakeCdpSession
{
    $page += ['pageKey' => 'key', 'guard' => 'guard', 'blocker' => null, 'setValue' => null];

    return (new FakeCdpSession)
        ->on('Page.getFrameTree', fn () => ['frameTree' => ['frame' => ['id' => 'F']]])
        ->on('Page.createIsolatedWorld', fn () => ['executionContextId' => 1])
        ->on('Runtime.evaluate', function (array $params) use ($page): array {
            preg_match('/^pageReader\.(\w+)\((?:\d+, (".*"))?/', $params['expression'], $call);
            $value = match (true) {
                ! isset($call[1]) => null,
                $call[1] === 'setValue' => $page['setValue'] ?? json_decode($call[2]),
                default => $page[$call[1]],
            };

            return ['result' => ['value' => $value]];
        });
}

function executorObservation(Action $action): Observation
{
    return new Observation('https://example.test/', 'T', 1000, 800, 0, 800, '', [$action], 0, 'key', [7 => 'guard']);
}

function control(string $kind = 'click', ?string $format = null, ?string $value = '', array $limits = []): Action
{
    return new Action(
        id: 'e1', kind: $kind, label: 'Go', node: 7, role: 'button', value: $value, format: $format,
        min: $limits['min'] ?? null, max: $limits['max'] ?? null, step: $limits['step'] ?? null,
        rect: new Rect(100, 200, 50, 20),
    );
}

function inputCalls(FakeCdpSession $cdp): array
{
    return array_values(array_filter($cdp->calls, fn (array $call) => str_starts_with($call['method'], 'Input.')));
}

function readerCalls(FakeCdpSession $cdp): array
{
    return array_values(array_filter(
        array_map(fn (array $call) => $call['params']['expression'] ?? null, $cdp->calls),
        fn (?string $expression) => $expression !== null && str_starts_with($expression, 'pageReader.'),
    ));
}

function run(FakeCdpSession $cdp, Action $action, ?string $text = null): Execution
{
    return (new Executor($cdp, new PageReader($cdp), waitSeconds: 0))->execute(executorObservation($action), $action, $text);
}

it('clicks at the centre of the rect after the page key, guard and hit test pass', function (): void {
    $cdp = executorPage();

    expect(run($cdp, control()))->toEqual(Execution::done())
        ->and(readerCalls($cdp))->toBe(['pageReader.pageKey()', 'pageReader.guard(7)', 'pageReader.blocker(7, 125, 210)'])
        ->and(array_map(fn ($c) => [$c['params']['type'], $c['params']['x'], $c['params']['y'], $c['params']['button']], inputCalls($cdp)))
        ->toBe([['mouseMoved', 125.0, 210.0, 'none'], ['mousePressed', 125.0, 210.0, 'left'], ['mouseReleased', 125.0, 210.0, 'left']]);
});

it('does nothing on a stale page', function (array $page, string $reason): void {
    $cdp = executorPage($page);

    expect(run($cdp, control(), 'x'))->toEqual(Execution::stale($reason))
        ->and(inputCalls($cdp))->toBe([]);
})->with([
    'page key changed' => [['pageKey' => 'other'], 'the page changed'],
    'guard changed' => [['guard' => 'other'], 'the control changed'],
    'control gone' => [['guard' => null], 'the control changed'],
]);

it('sends nothing when something covers the control, and reports it covered', function (): void {
    $cdp = executorPage(['blocker' => '<div#banner> "Accept cookies"']);

    expect(run($cdp, control()))->toEqual(Execution::covered('in the way: <div#banner> "Accept cookies"'))
        ->and(inputCalls($cdp))->toBe([]);
});

it('fills a text control by clicking, selecting all and inserting the text', function (): void {
    $cdp = executorPage();

    run($cdp, control('fill'), 'Fox');
    $calls = inputCalls($cdp);

    expect(array_column($calls, 'method'))->toBe([
        'Input.dispatchMouseEvent', 'Input.dispatchMouseEvent', 'Input.dispatchMouseEvent',
        'Input.dispatchKeyEvent', 'Input.dispatchKeyEvent', 'Input.insertText',
    ])
        ->and($calls[3]['params']['commands'])->toBe(['selectAll'])
        ->and($calls[5]['params'])->toBe(['text' => 'Fox']);
});

it('clears a text control when filled with an empty string', function (): void {
    $cdp = executorPage();

    run($cdp, control('fill'), '');

    expect(array_column(array_column(inputCalls($cdp), 'params'), 'key'))->toBe(['a', 'a', 'Backspace', 'Backspace']);
});

it('sets a valid value on a value input without touching the mouse or keyboard', function (): void {
    $cdp = executorPage();

    expect(run($cdp, control('fill', 'YYYY-MM-DD'), '2026-09-24'))->toEqual(Execution::done())
        ->and(readerCalls($cdp))->toContain('pageReader.setValue(7, "2026-09-24")')
        ->and(inputCalls($cdp))->toBe([]);
});

it('rejects an invalid value before looking at the page', function (): void {
    $cdp = executorPage();

    expect(run($cdp, control('fill', 'number', limits: ['max' => '50']), '60'))->toEqual(Execution::rejected('60 is above the maximum 50'))
        ->and($cdp->calls)->toBe([]);
});

it('reports a value the page cleared or clamped', function (string $format, string $held, string $asked): void {
    expect(run(executorPage(['setValue' => $held]), control('fill', $format), $asked))
        ->toEqual(Execution::altered("the page holds \"{$held}\" instead of \"{$asked}\""));
})->with([
    'cleared date' => ['YYYY-MM-DD', '', '2026-09-24'],
    'clamped range' => ['number', '50', '80'],
]);

it('accepts a colour or number the page holds in its own spelling', function (string $format, string $held, string $asked): void {
    expect(run(executorPage(['setValue' => $held]), control('fill', $format), $asked))->toEqual(Execution::done());
})->with([
    'lower-case colour' => ['#rrggbb', '#aabbcc', '#AABBCC'],
    'shortest number' => ['number', '40', '40.0'],
]);

it('reports a control that left the document while its value was set', function (): void {
    $cdp = executorPage()->on('Runtime.evaluate', function (array $params): array {
        $values = ['pageReader.pageKey()' => 'key', 'pageReader.guard(7)' => 'guard'];

        return ['result' => ['value' => $values[$params['expression']] ?? null]];
    });

    expect(run($cdp, control('select', value: 'gb')))->toEqual(Execution::altered('the control left the document'));
});

it('selects the option the action names', function (): void {
    $cdp = executorPage();

    expect(run($cdp, control('select', value: 'gb')))->toEqual(Execution::done())
        ->and(readerCalls($cdp))->toContain('pageReader.setValue(7, "gb")')
        ->and(inputCalls($cdp))->toBe([]);
});

it('scrolls with a wheel event at the viewport centre without any page check', function (): void {
    $cdp = executorPage(['pageKey' => 'changed']);
    $scroll = new Action(id: 'scroll_down', kind: 'scroll', label: 'Scroll down', delta: 560);

    expect(run($cdp, $scroll)->status)->toBe(ExecutionStatus::Done)
        ->and(readerCalls($cdp))->toBe([])
        ->and(inputCalls($cdp)[0]['params'])->toBe(['type' => 'mouseWheel', 'x' => 500, 'y' => 400, 'deltaX' => 0, 'deltaY' => 560]);
});

it('waits without touching the page', function (): void {
    $cdp = executorPage();

    expect(run($cdp, new Action(id: 'wait', kind: 'wait', label: 'Wait for the page to update'))->status)->toBe(ExecutionStatus::Done)
        ->and($cdp->calls)->toBe([]);
});

it('needs a value for a fill', function (): void {
    run(executorPage(), control('fill'));
})->throws(InvalidArgumentException::class, 'Fill action e1 needs a value');

it('refuses an action the run marked unavailable', function (): void {
    run(executorPage(), control()->unavailable());
})->throws(InvalidArgumentException::class, 'Action e1 is not available to this run');

it('refuses to act on a document-mode observation', function (): void {
    $cdp = executorPage();
    $action = control();
    $document = new Observation('https://example.test/', 'T', 1000, 800, 0, 800, '', [$action], 0, null, [], mode: ReadingMode::Document);

    expect(fn () => (new Executor($cdp, new PageReader($cdp)))->execute($document, $action))
        ->toThrow(InvalidArgumentException::class, 'A document-mode observation is for reading, not acting on')
        ->and($cdp->calls)->toBe([]);
});

it('hovers a control shown under the pointer and presses it once it appears', function (): void {
    $cdp = executorPage(['opaque' => true]);
    $action = new Action(id: 'e1', kind: 'click', label: 'Remove file', node: 7, role: 'button', rect: new Rect(100, 200, 50, 20), hover: true);

    expect(run($cdp, $action))->toEqual(Execution::done())
        ->and(readerCalls($cdp))->toBe(['pageReader.pageKey()', 'pageReader.guard(7)', 'pageReader.opaque(7, 600)', 'pageReader.blocker(7, 125, 210)'])
        ->and(array_column(array_column(inputCalls($cdp), 'params'), 'type'))->toBe(['mouseMoved', 'mouseMoved', 'mousePressed', 'mouseReleased']);
});

it('presses nothing when a hover control does not appear', function (): void {
    $cdp = executorPage(['opaque' => false]);
    $action = new Action(id: 'e1', kind: 'click', label: 'Remove file', node: 7, role: 'button', rect: new Rect(100, 200, 50, 20), hover: true);

    expect(run($cdp, $action))->toEqual(Execution::covered('the control did not appear under the pointer'))
        ->and(array_column(array_column(inputCalls($cdp), 'params'), 'type'))->toBe(['mouseMoved']);
});

it('brings a control out of view in a panel into view and presses it where it lands', function (): void {
    $cdp = executorPage(['reveal' => ['x' => 300, 'y' => 400, 'w' => 40, 'h' => 20]]);
    $action = new Action(id: 'e1', kind: 'click', label: 'Create folder', node: 7, role: 'button', rect: new Rect(100, 900, 50, 20), offscreen: 'panel');

    expect(run($cdp, $action))->toEqual(Execution::done())
        ->and(readerCalls($cdp))->toContain('pageReader.blocker(7, 320, 410)')
        ->and(inputCalls($cdp)[1]['params'])->toMatchArray(['type' => 'mousePressed', 'x' => 320.0, 'y' => 410.0]);
});

it('reports covered, pressing nothing, when a panel control will not come into view', function (): void {
    $cdp = executorPage(['reveal' => null]);
    $action = new Action(id: 'e1', kind: 'click', label: 'Create folder', node: 7, role: 'button', rect: new Rect(100, 900, 50, 20), offscreen: 'panel');

    expect(run($cdp, $action))->toEqual(Execution::covered('the control would not come into view'))
        ->and(inputCalls($cdp))->toBe([]);
});

/**
 * A drag from node 7 to a zone at node 8, with drags intercepted when $native says so.
 */
function dragRun(bool $native): FakeCdpSession
{
    $cdp = executorPage();
    $cdp->on('Input.dispatchMouseEvent', function (array $params) use ($cdp, $native): array {
        if ($native && $params['type'] === 'mouseMoved' && ($params['buttons'] ?? 0) === 1) {
            $cdp->emit('Input.dragIntercepted', ['data' => ['items' => [['mimeType' => 'text/plain', 'data' => 'task-7']], 'dragOperationsMask' => 1]]);
        }

        return [];
    });
    $source = new Action(id: 'e1', kind: 'drag', label: 'Task', node: 7, role: 'generic', rect: new Rect(100, 200, 50, 20));
    $zone = new Action(id: 'e2', kind: 'drop', label: 'Done', node: 8, role: 'region', rect: new Rect(400, 200, 100, 100));
    $observation = new Observation('https://example.test/', 'T', 1000, 800, 0, 800, '', [$source, $zone], 0, 'key', [7 => 'guard', 8 => 'guard']);
    (new Executor($cdp, new PageReader($cdp), waitSeconds: 0))->execute($observation, $source, null, $zone);

    return $cdp;
}

it('finishes a native drag the browser hands back with a drop at the zone', function (): void {
    $calls = array_map(fn ($c) => [$c['method'], $c['params']['type'] ?? $c['params']['enabled'] ?? null], inputCalls(dragRun(native: true)));

    expect($calls)->toBe([
        ['Input.setInterceptDrags', true],
        ['Input.dispatchMouseEvent', 'mousePressed'],
        ['Input.dispatchMouseEvent', 'mouseMoved'],
        ['Input.dispatchDragEvent', 'dragEnter'],
        ['Input.dispatchDragEvent', 'dragOver'],
        ['Input.dispatchDragEvent', 'drop'],
        ['Input.dispatchMouseEvent', 'mouseReleased'],
        ['Input.setInterceptDrags', false],
    ]);
});

it('moves a pointer drag all the way to the zone before letting go', function (): void {
    $moves = array_values(array_filter(inputCalls(dragRun(native: false)), fn ($c) => ($c['params']['type'] ?? null) === 'mouseMoved'));
    $release = array_values(array_filter(inputCalls(dragRun(native: false)), fn ($c) => ($c['params']['type'] ?? null) === 'mouseReleased'))[0];

    expect(count($moves))->toBe(12)
        ->and([end($moves)['params']['x'], end($moves)['params']['y']])->toBe([450.0, 250.0])
        ->and([$release['params']['x'], $release['params']['y']])->toEqual([450, 250]);
});

it('refuses a drag with no drop zone', function (): void {
    $cdp = executorPage();
    $source = new Action(id: 'e1', kind: 'drag', label: 'Task', node: 7, role: 'generic', rect: new Rect(100, 200, 50, 20));

    run($cdp, $source);
})->throws(InvalidArgumentException::class, 'needs a drop zone');
