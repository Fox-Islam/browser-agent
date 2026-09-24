<?php

declare(strict_types=1);

use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\ReadingMode;
use Phox\BrowserAgent\Reader\Rect;

function observationWith(array $actions): Observation
{
    return Observation::fromArray([
        'url' => 'https://example.test/form',
        'title' => 'Form',
        'viewport' => ['w' => 1120, 'h' => 780],
        'scroll' => ['y' => 560, 'height' => 3000, 'view' => 780],
        'text' => "Contact us\nName",
        'actions' => $actions,
        'omitted_actions' => 3,
        'page_key' => 'key',
        'guards' => ['5' => 'guard-5'],
    ]);
}

it('maps the reader payload onto typed fields', function (): void {
    $observation = observationWith([
        ['id' => 'e1', 'node' => 5, 'kind' => 'select', 'role' => 'combobox', 'label' => 'Size → Small', 'value' => 's', 'current_value' => 'Medium', 'omitted_options' => 12, 'expanded' => 'false', 'rect' => ['x' => 10, 'y' => 20, 'w' => 30, 'h' => 40]],
        ['id' => 'scroll_up', 'kind' => 'scroll', 'label' => 'Scroll up', 'delta' => -560],
        ['id' => 'wait', 'kind' => 'wait', 'label' => 'Wait for the page to update'],
    ]);

    expect($observation->url)->toBe('https://example.test/form')
        ->and($observation->title)->toBe('Form')
        ->and([$observation->viewportWidth, $observation->viewportHeight])->toBe([1120, 780])
        ->and([$observation->scrollY, $observation->scrollHeight])->toBe([560, 3000])
        ->and($observation->text)->toBe("Contact us\nName")
        ->and($observation->omittedActions)->toBe(3)
        ->and($observation->pageKey)->toBe('key')
        ->and($observation->guard(5))->toBe('guard-5')
        ->and($observation->guard(6))->toBeNull()
        ->and($observation->action('e1'))->toEqual(new Action(
            id: 'e1', kind: 'select', label: 'Size → Small', node: 5, role: 'combobox', value: 's',
            currentValue: 'Medium', omittedOptions: 12, expanded: 'false', rect: new Rect(10, 20, 30, 40),
        ))
        ->and($observation->action('scroll_up')->delta)->toBe(-560)
        ->and($observation->action('scroll_up')->node)->toBeNull()
        ->and($observation->action('e9'))->toBeNull();
});

it('is operable when a control can be clicked, filled or selected', function (string $kind): void {
    $observation = observationWith([
        ['id' => 'e1', 'node' => 1, 'kind' => $kind, 'role' => 'button', 'label' => 'Go', 'value' => '', 'rect' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]],
        ['id' => 'wait', 'kind' => 'wait', 'label' => 'Wait for the page to update'],
    ]);

    expect($observation->isOperable())->toBeTrue();
})->with(['click', 'fill', 'select']);

it('is not operable with only scroll and wait entries', function (): void {
    $observation = observationWith([
        ['id' => 'scroll_down', 'kind' => 'scroll', 'label' => 'Scroll down', 'delta' => 560],
        ['id' => 'wait', 'kind' => 'wait', 'label' => 'Wait for the page to update'],
    ]);

    expect($observation->isOperable())->toBeFalse();
});

it('reads a document-mode observation, which has no page key or guards', function (): void {
    $observation = Observation::fromArray([
        'url' => 'https://example.test/', 'title' => 'T', 'viewport' => ['w' => 1120, 'h' => 780],
        'scroll' => ['y' => 0, 'height' => 5000, 'view' => 780], 'mode' => 'document',
        'outline' => [['level' => 1, 'text' => 'Welcome']], 'text' => 'All of it',
        'actions' => [['id' => 'e1', 'node' => 1, 'kind' => 'click', 'role' => 'link', 'label' => 'Docs', 'value' => '', 'href' => '/docs', 'rect' => ['x' => 0, 'y' => 4000, 'w' => 10, 'h' => 10]],
            ['id' => 'e2', 'node' => 2, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Email', 'value' => '', 'required' => true, 'rect' => ['x' => 0, 'y' => 10, 'w' => 10, 'h' => 10]]],
        'omitted_actions' => 0, 'truncated' => ['outline' => 0, 'text' => 12, 'actions' => 3],
    ]);

    expect($observation->mode)->toBe(ReadingMode::Document)
        ->and($observation->pageKey)->toBeNull()
        ->and($observation->guards)->toBe([])
        ->and($observation->outline)->toBe([['level' => 1, 'text' => 'Welcome']])
        ->and($observation->truncated)->toBe(['outline' => 0, 'text' => 12, 'actions' => 3])
        ->and($observation->action('e1')->href)->toBe('/docs')
        ->and($observation->action('e2')->required)->toBeTrue()
        ->and($observation->action('e1')->required)->toBeFalse();
});
