<?php

declare(strict_types=1);

use Phox\BrowserAgent\Reader\Observation;

/**
 * An observation of a page holding the given actions, with the scroll and wait entries the reader adds.
 *
 * @param  list<array<string, mixed>>  $controls
 */
function observed(array $controls = [], array $scroll = ['y' => 0, 'height' => 780, 'view' => 780], string $text = 'Page text'): Observation
{
    $actions = [];
    foreach ($controls as $n => $control) {
        $actions[] = $control + ['id' => 'e' . ($n + 1), 'role' => 'button', 'value' => '', 'rect' => ['x' => 0, 'y' => 0, 'w' => 10, 'h' => 10]];
    }
    if ($scroll['y'] + $scroll['view'] < $scroll['height'] - 1) {
        $actions[] = ['id' => 'scroll_down', 'kind' => 'scroll', 'label' => 'Scroll down', 'delta' => 560];
    }
    $actions[] = ['id' => 'wait', 'kind' => 'wait', 'label' => 'Wait for the page to update'];

    return Observation::fromArray([
        'url' => 'https://example.test/form', 'title' => 'Form', 'viewport' => ['w' => 1120, 'h' => 780],
        'scroll' => $scroll, 'text' => $text, 'actions' => $actions, 'omitted_actions' => 0, 'page_key' => 'k', 'guards' => [],
    ]);
}

function formControls(): array
{
    return [
        ['node' => 1, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Name'],
        ['node' => 1, 'kind' => 'click', 'role' => 'textbox', 'label' => 'Open Name'],
        ['node' => 2, 'kind' => 'select', 'role' => 'combobox', 'label' => 'Size → Small', 'value' => 's', 'current_value' => 'Medium'],
        ['node' => 2, 'kind' => 'select', 'role' => 'combobox', 'label' => 'Size → Large', 'value' => 'l', 'current_value' => 'Medium'],
        ['node' => 3, 'kind' => 'click', 'role' => 'checkbox', 'label' => 'Subscribe', 'checked' => 'false'],
        ['node' => 4, 'kind' => 'click', 'label' => 'Send'],
    ];
}
