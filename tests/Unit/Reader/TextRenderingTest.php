<?php

declare(strict_types=1);

use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\TextRendering;

function rendered(array $observation): string
{
    return TextRendering::of(Observation::fromArray($observation + [
        'url' => 'https://example.test/contact', 'title' => 'Contact', 'viewport' => ['w' => 1120, 'h' => 780],
        'scroll' => ['y' => 0, 'height' => 3000, 'view' => 780], 'omitted_actions' => 0,
    ]));
}

it('renders a golden document observation byte for byte', function (): void {
    $text = rendered([
        'mode' => 'document',
        'outline' => [['level' => 1, 'text' => 'Contact us'], ['level' => 2, 'text' => 'Write to us'], ['level' => 4, 'text' => 'Opening hours']],
        'text' => "Contact us\nWe reply within a day.",
        'actions' => [
            ['node' => 11, 'kind' => 'click', 'role' => 'link', 'label' => 'Home', 'href' => '/'],
            ['node' => 12, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Your name', 'required' => true],
            ['node' => 13, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Email', 'value' => 'fox@example.test'],
            ['node' => 14, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Visit date', 'format' => 'YYYY-MM-DD'],
            ['node' => 15, 'kind' => 'select', 'role' => 'combobox', 'label' => 'Country → Albania', 'value' => 'al', 'current_value' => 'United Kingdom', 'omitted_options' => 214],
            ['node' => 15, 'kind' => 'select', 'role' => 'combobox', 'label' => 'Country → Algeria', 'value' => 'dz', 'current_value' => 'United Kingdom', 'omitted_options' => 214],
            ['node' => 17, 'kind' => 'click', 'role' => 'checkbox', 'label' => 'Subscribe', 'checked' => 'false'],
            ['node' => 18, 'kind' => 'click', 'role' => 'button', 'label' => 'More', 'expanded' => 'true'],
            ['node' => 19, 'kind' => 'click', 'role' => 'button', 'label' => 'Send', 'value' => 'Send', 'submits' => true],
            ['node' => 20, 'kind' => 'click', 'role' => 'textbox', 'label' => 'Reference', 'value' => 'ABC-123'],
            ['node' => 21, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Company', 'value' => 'Company'],
            ['node' => 22, 'kind' => 'click', 'role' => 'link', 'label' => 'Partner', 'href' => 'https://partner.example/'],
        ],
        'truncated' => ['outline' => 0, 'text' => 1520, 'actions' => 3],
    ]);

    expect($text)->toBe(<<<'TEXT'
        URL: https://example.test/contact
        Title: Contact
        Outline:
        Contact us
          Write to us
              Opening hours
        Text:
        Contact us
        We reply within a day.
        Controls:
        - [11] link: Home -> /
        - [12] textbox: Your name [fill] (required)
        - [13] textbox: Email [fill] value=fox@example.test
        - [14] textbox: Visit date [fill] format=YYYY-MM-DD
        - [15] combobox: Country [select] selected=United Kingdom
          options: Albania, Algeria (+214 more)
        - [17] checkbox: Subscribe (unchecked)
        - [18] button: More (expanded)
        - [19] button: Send (submits)
        - [20] textbox: Reference value=ABC-123
        - [21] textbox: Company [fill] value=Company
        - [22] link: Partner -> https://partner.example/
        Cut to fit: 0 headings, 1520 characters of text, 3 controls

        TEXT);
});

it('keeps every section header on an empty page and leaves out the cut line when nothing was cut', function (): void {
    expect(rendered(['mode' => 'document', 'outline' => [], 'text' => '', 'actions' => [], 'truncated' => ['outline' => 0, 'text' => 0, 'actions' => 0]]))
        ->toBe("URL: https://example.test/contact\nTitle: Contact\nOutline:\nText:\nControls:\n");
});

it('shows a button value that differs from its label', function (): void {
    expect(rendered(['mode' => 'document', 'outline' => [], 'text' => '', 'truncated' => ['outline' => 0, 'text' => 0, 'actions' => 0],
        'actions' => [['node' => 3, 'kind' => 'click', 'role' => 'button', 'label' => 'Go', 'value' => 'search']]]))
        ->toEndWith("Controls:\n- [3] button: Go value=search\n");
});

it('renders a viewport observation once per control, without scroll and wait entries', function (): void {
    $text = rendered([
        'text' => 'Hello',
        'actions' => [
            ['id' => 'e1', 'node' => 1, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Name', 'value' => '', 'rect' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]],
            ['id' => 'e2', 'node' => 1, 'kind' => 'click', 'role' => 'textbox', 'label' => 'Open Name', 'value' => '', 'rect' => ['x' => 0, 'y' => 0, 'w' => 1, 'h' => 1]],
            ['id' => 'scroll_down', 'kind' => 'scroll', 'label' => 'Scroll down', 'delta' => 560],
            ['id' => 'wait', 'kind' => 'wait', 'label' => 'Wait for the page to update'],
        ],
        'page_key' => 'k', 'guards' => [],
    ]);

    expect($text)->toEndWith("Text:\nHello\nControls:\n- [1] textbox: Name [fill]\n");
});
