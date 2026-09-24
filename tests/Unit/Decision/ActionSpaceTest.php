<?php

declare(strict_types=1);

use Phox\BrowserAgent\Decision\ActionSpace;

require_once __DIR__ . '/helpers.php';

it('gives each element one index with operation-specific targets', function (): void {
    $space = ActionSpace::of(observed(formControls())->actions);

    expect(array_column($space->elements, 'label'))->toBe(['Name', 'Size', 'Subscribe', 'Send'])
        ->and($space->elements[0]['operations'])->toBe(['TYPE_TEXT', 'CLICK'])
        ->and(array_map('strval', array_keys($space->targets['CLICK'])))->toBe(['1', '3', '4'])
        ->and(array_keys($space->targets['SELECT']))->toBe(['2:1', '2:2'])
        ->and($space->elements[1]['value'])->toBe('Medium')
        ->and($space->elements[1]['options'][1])->toBe(['index' => '2:2', 'label' => 'Size → Large', 'value' => 'l'])
        ->and($space->elements[2]['checked'])->toBe('false')
        ->and(array_keys($space->controls))->toBe(['WAIT']);
});

it('withholds suppressed controls but never all of them', function (): void {
    $space = ActionSpace::of(observed([['node' => 1, 'kind' => 'click', 'label' => 'Go'], ['node' => 2, 'kind' => 'click', 'label' => 'Stay']])->actions);

    expect(array_column($space->without([['Go', 'click']])->targets['CLICK'], 'label'))->toBe(['Stay'])
        ->and($space->without([['Go', 'click'], ['Stay', 'click']]))->toBe($space);
});

it('lists an unavailable control without operations and offers it as no target', function (): void {
    $actions = observed(formControls())->actions;
    $marked = array_map(fn ($a) => in_array($a->label, ['Send', 'Name'], true) ? $a->unavailable() : $a, $actions);
    $space = ActionSpace::of($marked);

    expect($space->elements[3])->toMatchArray(['label' => 'Send', 'operations' => [], 'available' => false])
        ->and($space->elements[0])->toMatchArray(['label' => 'Name', 'operations' => ['CLICK']])
        ->and($space->elements[0])->not->toHaveKey('available')
        ->and(array_map('strval', array_keys($space->targets['CLICK'])))->toBe(['1', '3'])
        ->and($space->targets)->not->toHaveKey('TYPE_TEXT');
});
