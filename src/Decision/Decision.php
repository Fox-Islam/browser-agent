<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

/**
 * One chosen step: an action id from the observation, or DONE or BLOCKED.
 */
final readonly class Decision
{
    /**
     * @param  array<string, float>  $probabilities  per action id, for the chosen operation's targets
     * @param  array<string, float>  $operationProbabilities
     * @param  list<PlanReading>  $plan  one reading per outstanding sub-goal, in the order asked
     * @param  array<string, mixed>  $usage
     * @param  int|null  $reusedFor  the sub-goal a held decision was taken for
     */
    public function __construct(
        public string $choice,
        public string $operation,
        public ?string $target,
        public float $confidence,
        public array $probabilities,
        public array $operationProbabilities = [],
        public array $plan = [],
        public string $model = '',
        public array $usage = [],
        public int $latencyMs = 0,
        public ?int $reusedFor = null,
    ) {}

    public function probability(): float
    {
        return $this->probabilities[$this->choice] ?? 0.0;
    }
}
