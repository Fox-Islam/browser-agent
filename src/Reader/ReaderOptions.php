<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

final readonly class ReaderOptions
{
    public function __construct(
        public int $maxText = 6000,
        public int $maxActions = 250,
        public int $scrollStep = 560,
    ) {}

    /**
     * @return array{max_text: int, max_actions: int, scroll_step: int}
     */
    public function toArray(): array
    {
        return [
            'max_text' => $this->maxText,
            'max_actions' => $this->maxActions,
            'scroll_step' => $this->scrollStep,
        ];
    }
}
