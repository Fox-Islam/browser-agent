<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Agent\Step;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;

/**
 * Asks TypeSafe which operation to take and, in the same request, which target each operation
 * would take. Only the chosen operation's target is used, so a target can never be applied with
 * an operation it was not offered for. Targets are observed controls; the model never emits a
 * selector or code.
 */
final readonly class Decider
{
    private const int RECENT_STEPS = 10;

    public function __construct(
        private ModelClient $client,
        private ModelConfig $config,
        private PlanQuestions $plans = new PlanQuestions,
    ) {}

    /**
     * @param  list<Step>  $history
     * @param  list<string>  $pending  outstanding sub-goals, each asked about separately
     * @param  list<array{string, string}>  $suppress  label and kind pairs to withhold
     */
    public function choose(Observation $page, string $goal, array $history, array $pending = [], array $suppress = []): Decision
    {
        $space = ActionSpace::of($page->actions)->without($suppress);
        $operations = self::operations($space);
        // Read-only runs and the resubmit guard leave controls on the page that cannot be chosen;
        // without this the model reads a goal about seeing one as blocked.
        $notes = array_filter($space->elements, fn (array $e) => ($e['available'] ?? true) === false) === [] ? [] : [Prompts::UNAVAILABLE];
        $rules = [Prompts::NEXT_ACTION, ...$notes];
        if (Scrolling::stillHelps($page, $history)) {
            // Withheld instead of argued against: an option that is not offered cannot be taken.
            unset($operations['BLOCKED']);
            $rules[] = round(Scrolling::unseen($page) * 100) . '% of this page is below the viewport and has not been seen.';
        }
        // A head offering one candidate answers 1.00 whatever the element is, which reads as
        // certainty downstream, so a single candidate is taken without asking.
        $settled = array_map(fn (array $c) => (string) array_key_first($c), array_filter($space->targets, fn (array $c) => count($c) === 1));
        $questions = ['operation' => Questions::choice($operations, Questions::instructions($goal, $rules))]
            + Questions::targets($goal, $space->targets, $settled, notes: $notes)
            + $this->plans->questions($pending, $operations, $space->targets, $settled, $notes);
        $started = hrtime(true);
        $result = $this->client->post($this->config->decisionUrl(), $this->config->typesafeKey, [
            'model' => $this->config->typesafeModel,
            'state' => [
                'page' => ['url' => $page->url, 'title' => $page->title, 'text' => $page->text],
                'elements' => $space->elements,
                'recent_actions' => array_map(fn (Step $s) => $s->recent(), array_slice($history, -self::RECENT_STEPS)),
            ],
            'questions' => $questions,
        ]);
        $answers = is_array($result['answers'] ?? null) ? $result['answers'] : [];

        return $this->decision($answers, $space, $operations, $settled, $pending, [
            'model' => (string) ($result['model'] ?? ''),
            'usage' => is_array($result['usage'] ?? null) ? $result['usage'] : [],
            'latency' => (int) round((hrtime(true) - $started) / 1e6),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function operations(ActionSpace $space): array
    {
        $labels = ['CLICK' => Prompts::OPERATION_CLICK, 'TYPE_TEXT' => Prompts::OPERATION_TYPE_TEXT, 'SELECT' => Prompts::OPERATION_SELECT];
        $operations = array_intersect_key($labels, $space->targets);
        foreach ($space->controls as $name => $control) {
            $operations[$name] = $control->label;
        }

        return $operations + ['DONE' => Prompts::OPERATION_DONE, 'BLOCKED' => Prompts::OPERATION_BLOCKED];
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, string>  $operations
     * @param  array<string, string>  $settled
     * @param  list<string>  $pending
     * @param  array{model: string, usage: array<string, mixed>, latency: int}  $call
     */
    private function decision(array $answers, ActionSpace $space, array $operations, array $settled, array $pending, array $call): Decision
    {
        $answer = Choices::validate($answers['operation'] ?? null, array_map('strval', array_keys($operations)));
        $operation = $answer['choice'];
        [$choice, $target, $probabilities] = $this->target($answers, $space, $operation, $answer, $settled);

        return new Decision(
            choice: $choice,
            operation: $operation,
            target: $target,
            confidence: $answer['confidence'],
            probabilities: $probabilities,
            operationProbabilities: $answer['probabilities'],
            plan: $this->plans->read($answers, $pending, array_map('strval', array_keys($operations)), $space->targets, $settled),
            model: $call['model'],
            usage: $call['usage'],
            latencyMs: $call['latency'],
        );
    }

    /**
     * The action id, target index and per-action probabilities for the chosen operation. Unused
     * target heads cannot cause an action, so only the chosen operation's head is validated.
     *
     * @param  array<string, mixed>  $answers
     * @param  array{choice: string, probabilities: array<string, float>, confidence: float}  $answer
     * @param  array<string, string>  $settled
     * @return array{string, string|null, array<string, float>}
     */
    private function target(array $answers, ActionSpace $space, string $operation, array $answer, array $settled): array
    {
        $candidates = $space->targets[$operation] ?? null;
        if ($candidates === null) {
            $choice = isset($space->controls[$operation]) ? $space->controls[$operation]->id : $operation;

            return [$choice, null, [$choice => $answer['probabilities'][$operation]]];
        }
        if (isset($settled[$operation])) {
            $action = $candidates[$settled[$operation]];

            return [$action->id, $settled[$operation], [$action->id => $answer['probabilities'][$operation]]];
        }
        $head = Choices::validate($answers[mb_strtolower($operation) . '_target'] ?? null, array_map('strval', array_keys($candidates)));
        $probabilities = [];
        foreach ($candidates as $index => $action) {
            $probabilities[$action->id] = $head['probabilities'][(string) $index];
        }

        return [$candidates[$head['choice']]->id, $head['choice'], $probabilities];
    }
}
