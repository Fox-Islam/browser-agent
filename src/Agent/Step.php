<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

/**
 * One executed action. It is recorded before the page is observed again, so an observation that
 * fails cannot erase what was done; what the action did to the page is filled in afterwards.
 */
final class Step
{
    /** Null until the page after the action has been observed. */
    public ?bool $pageChanged = null;

    /** @var array{y: int, height: int, view: int}|null */
    public ?array $scroll = null;

    /** The fingerprint of the page the action left, once observed. */
    public ?string $result = null;

    /**
     * @param  string  $label  the action's label, which is how scripts and reports name controls
     * @param  string|null  $note  why the page holds something other than what was set
     * @param  array<string, mixed>  $usage
     */
    public function __construct(
        public readonly int $number,
        public readonly string $label,
        public readonly string $kind,
        public readonly string $choice,
        public readonly string $operation,
        public readonly float $probability,
        public readonly float $confidence,
        public readonly ?string $text,
        public string $url,
        public int $elapsedMs,
        public readonly int $decisionLatencyMs = 0,
        public readonly ?string $textModel = null,
        public readonly int $textLatencyMs = 0,
        public readonly array $usage = [],
        public readonly ?string $note = null,
        public readonly bool $submits = false,
        public readonly ?int $node = null,
    ) {}

    /**
     * What the decision model is told about a recent step.
     *
     * @return array{action: string, kind: string, text: string|null, page_changed: bool|null}
     */
    public function recent(): array
    {
        return ['action' => $this->label, 'kind' => $this->kind, 'text' => $this->text, 'page_changed' => $this->pageChanged];
    }

    public function isScrollDown(): bool
    {
        return $this->kind === 'scroll' && str_contains(mb_strtolower($this->label), 'down');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->number,
            'action' => $this->label,
            'kind' => $this->kind,
            'choice' => $this->choice,
            'operation' => $this->operation,
            'probability' => $this->probability,
            'confidence' => $this->confidence,
            'text' => $this->text,
            'note' => $this->note,
            'page_changed' => $this->pageChanged,
            'url' => $this->url,
            'scroll' => $this->scroll,
            'decision_latency_ms' => $this->decisionLatencyMs,
            'text_model' => $this->textModel,
            'text_latency_ms' => $this->textLatencyMs,
            'usage' => $this->usage,
            'elapsed_ms' => $this->elapsedMs,
        ];
    }
}
