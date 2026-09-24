<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Decision\Decision;
use Phox\BrowserAgent\Decision\PlanReading;
use Phox\BrowserAgent\Reader\Action;

/**
 * Decisions taken for later sub-goals, kept until they apply or are replaced. A held answer is
 * re-resolved by label and kind against the page as it is when used, never by node: a node id
 * survives a change of meaning.
 */
final class HeldDecisions
{
    /** @var array<int, PlanReading> sub-goal index => reading */
    public array $held = [];

    /**
     * @param  list<int>  $outstanding
     * @param  list<PlanReading>  $readings  in the order of $outstanding
     * @param  list<int>  $satisfied
     */
    public function update(array $outstanding, array $readings, array $satisfied): void
    {
        $this->held = [];
        foreach ($outstanding as $position => $index) {
            $reading = $readings[$position] ?? null;
            if ($reading?->label !== null && ! in_array($index, $satisfied, true)) {
                $this->held[$index] = $reading;
            }
        }
    }

    /**
     * The held decision for the first outstanding sub-goal, when it applies to the current page,
     * as a decision on the current observation, so the executor's freshness checks apply to it.
     * Answers held for later sub-goals wait their turn: they were given on a page where the
     * earlier steps had not happened, and a control like "View history" is on every page.
     *
     * @param  list<int>  $outstanding
     */
    public function reuse(RunState $state, array $outstanding): ?Decision
    {
        $index = $outstanding[0] ?? null;
        $reading = $index === null ? null : ($this->held[$index] ?? null);
        $action = $reading === null ? null : $this->applicable($state, $reading);
        if ($action === null) {
            return null;
        }
        unset($this->held[$index]);

        return new Decision(
            choice: $action->id,
            operation: (string) $reading->operation,
            target: null,
            confidence: $reading->confidence ?? 0.0,
            probabilities: [$action->id => $reading->confidence ?? 0.0],
            model: 'held',
            reusedFor: $index,
        );
    }

    private function applicable(RunState $state, PlanReading $reading): ?Action
    {
        if (in_array($reading->operation, ['DONE', 'BLOCKED', 'WAIT'], true)) {
            return null;
        }
        $matches = array_values(array_filter($state->page->actions, fn (Action $a) => $a->available && $a->label === $reading->label && $a->kind === $reading->kind));
        $action = count($matches) === 1 ? $matches[0] : null;
        // Sub-goals asked against one page converge on whatever control it makes obvious, so a
        // held answer is often the action just taken; repeating it is not progress.
        $justTaken = $action !== null && array_filter(array_slice($state->history, -2), fn (Step $s) => $s->label === $action->label && $s->kind === $action->kind) !== [];
        $alreadyFilled = $action?->kind === 'fill' && ($action->value ?? '') !== '';

        return $justTaken || $alreadyFilled ? null : $action;
    }
}
