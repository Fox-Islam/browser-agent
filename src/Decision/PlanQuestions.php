<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Reader\Action;

/**
 * Questions about each outstanding sub-goal, riding in the decision request already being sent.
 * A whole-task DONE is one broad judgement and answers weakly when the page arguably fits the goal
 * already; a sub-goal asks something narrow enough to answer sharply. Each sub-goal also gets a
 * full decision: asking costs almost nothing next to a round trip, and an answer that applies
 * when its turn comes replaces the call that turn would need.
 */
final readonly class PlanQuestions
{
    /**
     * @param  list<string>  $pending
     * @param  array<string, string>  $operations
     * @param  array<string, array<string, Action>>  $targets
     * @param  array<string, string>  $settled
     * @param  list<string>  $notes  rules added after the standard ones
     * @return array<string, array<string, mixed>>
     */
    public function questions(array $pending, array $operations, array $targets, array $settled, array $notes = []): array
    {
        $questions = [];
        foreach ($pending as $offset => $step) {
            $questions["plan{$offset}_satisfied"] = [
                'type' => 'noul',
                'instructions' => implode("\n\n", [Prompts::STEP_SATISFIED, ...$notes, "Step: {$step}"]),
                'criteria' => ['true' => Prompts::STEP_SATISFIED_TRUE, 'false' => Prompts::STEP_SATISFIED_FALSE],
            ];
        }
        foreach ($pending as $offset => $step) {
            $questions["plan{$offset}_operation"] = Questions::choice($operations, Questions::instructions($step, [Prompts::NEXT_ACTION, ...$notes]));
            $questions += Questions::targets($step, $targets, $settled, "plan{$offset}_", $notes);
        }

        return $questions;
    }

    /**
     * One reading per sub-goal. A malformed answer must not stop the run, so it reads as unknown
     * instead of raising.
     *
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $pending
     * @param  list<string>  $operationIds
     * @param  array<string, array<string, Action>>  $targets
     * @param  array<string, string>  $settled
     * @return list<PlanReading>
     */
    public function read(array $answers, array $pending, array $operationIds, array $targets, array $settled): array
    {
        $readings = [];
        foreach (array_keys($pending) as $offset) {
            $noul = $answers["plan{$offset}_satisfied"]['noul'] ?? null;
            $satisfied = (is_int($noul) || is_float($noul)) && $noul >= 0 && $noul <= 1 ? (float) $noul : null;
            $readings[] = $this->decision($answers, $offset, $satisfied, $operationIds, $targets, $settled);
        }

        return $readings;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  list<string>  $operationIds
     * @param  array<string, array<string, Action>>  $targets
     * @param  array<string, string>  $settled
     */
    private function decision(array $answers, int $offset, ?float $satisfied, array $operationIds, array $targets, array $settled): PlanReading
    {
        try {
            $answer = Choices::validate($answers["plan{$offset}_operation"] ?? null, $operationIds);
        } catch (ModelException) {
            return new PlanReading($satisfied);
        }
        $operation = $answer['choice'];
        $candidates = $targets[$operation] ?? null;
        try {
            $index = match (true) {
                $candidates === null => null,
                isset($settled[$operation]) => $settled[$operation],
                default => Choices::validate($answers["plan{$offset}_" . mb_strtolower($operation) . '_target'] ?? null, array_map('strval', array_keys($candidates)))['choice'],
            };
        } catch (ModelException) {
            $index = null;
        }
        $action = $index === null ? null : $candidates[$index];

        return new PlanReading($satisfied, $operation, $action?->label, $action?->kind, $answer['confidence']);
    }
}
