<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

/**
 * What a decision said about one outstanding sub-goal: how likely it is already satisfied, and
 * the operation and control it would take. The control is held by label and kind, never by node:
 * a node survives a change of meaning.
 */
final readonly class PlanReading
{
    public function __construct(
        public ?float $satisfied = null,
        public ?string $operation = null,
        public ?string $label = null,
        public ?string $kind = null,
        public ?float $confidence = null,
    ) {}
}
