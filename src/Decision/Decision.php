<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

/**
 * One chosen step: an action id from the observation, or DONE or BLOCKED. A drag also names its
 * drop zone.
 */
final readonly class Decision
{
    /**
     * @param  array<string, float>  $probabilities  per action id, for the chosen operation's targets
     * @param  array<string, float>  $operationProbabilities
     * @param  list<PlanReading>  $plan  one reading per outstanding sub-goal, in the order asked
     * @param  array<string, mixed>  $usage
     * @param  int|null  $reusedFor  the sub-goal a held decision was taken for
     * @param  string|null  $drop  for a drag, the action id of the drop zone it ends on
     * @param  bool  $screenshot  whether the request carried a screenshot of the page
     * @param  float|null  $screenDone  how strongly the screenshot alone shows the goal done
     * @param  Decision|null  $firstLook  the text-only decision this one was asked again after
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
        public ?string $drop = null,
        public bool $screenshot = false,
        public ?float $screenDone = null,
        public ?self $firstLook = null,
    ) {}

    /**
     * This decision, recorded as asked again after $first.
     */
    public function after(self $first): self
    {
        return new self(...[...get_object_vars($this), 'firstLook' => $first]);
    }

    public function probability(): float
    {
        return $this->probabilities[$this->choice] ?? 0.0;
    }
}
