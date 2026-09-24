<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

final readonly class ReaderOptions
{
    public function __construct(
        public int $maxText = 6000,
        public int $maxActions = 250,
        public int $scrollStep = 560,
        public int $maxOptions = 25,
        public int $maxLabel = 200,
        public ReadingMode $mode = ReadingMode::Viewport,
        public int $maxDocument = 50000,
        public int $maxOutline = 200,
    ) {}

    /**
     * @return array{max_text: int, max_actions: int, scroll_step: int, max_options: int, max_label: int, mode: string, max_document: int, max_outline: int}
     */
    public function toArray(): array
    {
        return [
            'max_text' => $this->maxText,
            'max_actions' => $this->maxActions,
            'scroll_step' => $this->scrollStep,
            'max_options' => $this->maxOptions,
            'max_label' => $this->maxLabel,
            'mode' => $this->mode->value,
            'max_document' => $this->maxDocument,
            'max_outline' => $this->maxOutline,
        ];
    }

    public function withMode(ReadingMode $mode): self
    {
        return new self($this->maxText, $this->maxActions, $this->scrollStep, $this->maxOptions, $this->maxLabel, $mode, $this->maxDocument, $this->maxOutline);
    }
}
