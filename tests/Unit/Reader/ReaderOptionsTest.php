<?php

declare(strict_types=1);

use Phox\BrowserAgent\Reader\ReaderOptions;
use Phox\BrowserAgent\Reader\ReadingMode;

it('passes the options under the names the page reader expects', function (): void {
    expect((new ReaderOptions(10, 20, 30, 40, 50, ReadingMode::Document, 60, 70))->toArray())
        ->toBe(['max_text' => 10, 'max_actions' => 20, 'scroll_step' => 30, 'max_options' => 40, 'max_label' => 50, 'mode' => 'document', 'max_document' => 60, 'max_outline' => 70]);
});

it('defaults to the page reader contract limits', function (): void {
    expect((new ReaderOptions)->toArray())
        ->toBe(['max_text' => 6000, 'max_actions' => 250, 'scroll_step' => 560, 'max_options' => 25, 'max_label' => 200, 'mode' => 'viewport', 'max_document' => 50000, 'max_outline' => 200]);
});

it('switches mode and keeps every limit', function (): void {
    expect((new ReaderOptions(maxLabel: 9))->withMode(ReadingMode::Document)->toArray())
        ->toMatchArray(['mode' => 'document', 'max_label' => 9, 'max_document' => 50000]);
});

it('matches the defaults the Chrome tests read with', function (): void {
    $harness = file_get_contents(__DIR__ . '/../../../reader/tests/chrome.js');
    preg_match('/export const DEFAULTS = (\{.*\});/', $harness, $match);
    $defaults = json_decode(str_replace("'", '"', preg_replace('/(\w+):/', '"$1":', $match[1])), true, flags: JSON_THROW_ON_ERROR);

    expect($defaults)->toBe((new ReaderOptions)->toArray());
});
