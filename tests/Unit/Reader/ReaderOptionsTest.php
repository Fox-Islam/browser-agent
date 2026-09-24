<?php

declare(strict_types=1);

use Phox\BrowserAgent\Reader\ReaderOptions;

it('passes the options under the names the page reader expects', function (): void {
    expect((new ReaderOptions(maxText: 10, maxActions: 20, scrollStep: 30))->toArray())
        ->toBe(['max_text' => 10, 'max_actions' => 20, 'scroll_step' => 30]);
});

it('defaults to the page reader contract limits', function (): void {
    expect((new ReaderOptions)->toArray())
        ->toBe(['max_text' => 6000, 'max_actions' => 250, 'scroll_step' => 560]);
});
