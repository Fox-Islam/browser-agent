<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Decision\ModelException;
use Phox\BrowserAgent\Decision\PlanReading;
use Phox\BrowserAgent\Decision\TextHelper;
use Phox\BrowserAgent\Decision\UnusableValue;
use Phox\BrowserAgent\Reader\Action;

/**
 * Values for the fields a run fills. Fields a step will need are asked for in one call; a value
 * generated for a decision that went stale is reused only while everything the helper was told is
 * identical, and is dropped once it has been typed.
 */
final class FieldValues
{
    /** How many times a field value may come back unusable before the run gives up on it. */
    public const int ATTEMPTS = 2;

    /** @var array<string, string> field label => value fetched ahead */
    private array $ready = [];

    /** @var array{string, string, array<string, mixed>}|null what the helper was told, its value and its cost */
    private ?array $pending = null;

    private int $failures = 0;

    public function __construct(private readonly TextHelper $helper) {}

    /**
     * The value to type into $field for $goal, and what asking cost, or null when the value was
     * fetched earlier.
     *
     * @return array{string, array<string, mixed>|null}
     *
     * @throws StalePage when the helper gave nothing usable and the decision can be taken again
     * @throws UnusableValue when it has done so too often
     */
    public function valueFor(RunState $state, Action $field, string $goal): array
    {
        if (isset($this->ready[$field->label])) {
            $value = $this->ready[$field->label];
            unset($this->ready[$field->label]);

            return [$value, null];
        }
        $asked = json_encode([$goal, $field->label, $field->value, $field->format, $state->page->title, $state->page->text, array_map(fn (Step $s) => [$s->label, $s->text], array_slice($state->history, -6))], JSON_THROW_ON_ERROR);
        if ($this->pending !== null && $this->pending[0] === $asked) {
            return [$this->pending[1], $this->pending[2]];
        }

        return $this->ask($state, $field, $goal, $asked);
    }

    /**
     * A value was typed, or refused by the executor; either way it is not offered again.
     */
    public function spent(string $label): void
    {
        $this->pending = null;
        unset($this->ready[$label]);
    }

    /**
     * Asks, in one call, for every field value this step will need: the field being typed into,
     * fields held for later sub-goals, and the other empty fields on the page with names of their
     * own. A value fetched and not used adds no call; values fetched one at a time add a call
     * each.
     *
     * @param  array<int, PlanReading>  $held
     */
    public function prefetch(RunState $state, array $held): void
    {
        $page = $state->page;
        $fills = array_values(array_filter($page->actions, fn (Action $a) => $a->kind === 'fill' && $a->available));
        // Kept while the field is present and empty; dropped once it holds something.
        $holding = array_map(fn (Action $a) => $a->label, array_filter($fills, fn (Action $a) => ($a->value ?? '') !== ''));
        $this->ready = array_diff_key($this->ready, array_flip($holding));
        $wanted = $this->wanted($state, $fills, $held);
        if (count($wanted) < 2) {
            return;
        }
        // Keyed by position: a label has to survive being echoed back exactly, and one tidied on
        // the way is a value dropped and fetched again.
        $numbered = array_combine(array_map(fn (int $n) => "f{$n}", array_keys(array_values($wanted))), array_values($wanted));
        $labels = array_combine(array_keys($numbered), array_keys($wanted));
        try {
            [$values, $spent] = $this->helper->values($numbered, $page, $state->history);
        } catch (ModelException) {
            return;
        }
        if ($spent !== null) {
            $state->textCalls[] = $spent + ['field' => count($values) . ' fields', 'value' => null];
        }
        foreach ($values as $id => $value) {
            $this->ready[$labels[$id]] = $value;
        }
    }

    /**
     * @param  list<Action>  $fills
     * @param  array<int, PlanReading>  $held
     * @return array<string, array{string, Action}>
     */
    private function wanted(RunState $state, array $fills, array $held): array
    {
        $typing = $state->decision?->operation === 'TYPE_TEXT';
        $acting = $typing ? $state->page->action((string) $state->decision?->choice) : null;
        $wanted = $acting?->kind === 'fill' ? [$acting->label => [$state->goal, $acting]] : [];
        foreach ($held as $index => $reading) {
            $matches = $reading->operation === 'TYPE_TEXT' ? array_values(array_filter($fills, fn (Action $a) => $a->label === $reading->label)) : [];
            if (count($matches) === 1 && ($matches[0]->value ?? '') === '') {
                $wanted[$matches[0]->label] ??= [$state->plan[$index], $matches[0]];
            }
        }
        $counts = array_count_values(array_map(fn (Action $a) => $a->label, $fills));
        foreach ($typing ? $fills : [] as $field) {
            if (($field->value ?? '') === '' && $counts[$field->label] === 1) {
                $wanted[$field->label] ??= [$state->goal, $field];
            }
        }

        return array_diff_key($wanted, $this->ready);
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function ask(RunState $state, Action $field, string $goal, string $asked): array
    {
        try {
            [$value, $spent] = $this->helper->value($goal, $field, $state->page, $state->history);
        } catch (UnusableValue $e) {
            // Nothing has been typed, so taking the decision again is not a mutation retry.
            if (++$this->failures > self::ATTEMPTS) {
                throw $e;
            }

            throw new StalePage('Text helper gave nothing usable. Choose again.', previous: $e);
        }
        $this->failures = 0;
        $this->pending = [$asked, $value, $spent];
        $state->textCalls[] = $spent + ['field' => $field->label, 'value' => $value];

        return [$value, $spent];
    }
}
