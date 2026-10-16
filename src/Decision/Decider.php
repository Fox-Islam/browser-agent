<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Agent\AfterAction;
use Phox\BrowserAgent\Agent\Step;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\ScreenCapture;
use Phox\TypeSafe\Assets\Image;

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
        private DecisionClient $client,
        private ModelConfig $config,
        private PlanQuestions $plans = new PlanQuestions,
        private ?ScreenCapture $screen = null,
        private ?AfterAction $after = null,
    ) {}

    /**
     * @param  list<Step>  $history
     * @param  list<string>  $pending  outstanding sub-goals, each asked about separately
     * @param  list<array{string, string}>  $suppress  label and kind pairs to withhold
     */
    public function choose(Observation $page, string $goal, array $history, array $pending = [], array $suppress = []): Decision
    {
        $space = self::dragsWithZones(ActionSpace::of($page->actions)->without($suppress));
        $first = $this->decide($page, $space, $goal, $history, $pending, $this->screenshot($page, $space, $goal, look: false));
        if ($first->screenshot || $this->config->images !== ImageMode::Auto || ! Looking::secondLook($first->operation, $first->confidence)) {
            return $first;
        }
        // Asked again with a screenshot, which stands in place of the text-only answer; that one is
        // kept on it so its cost is counted. Without a screenshot to send, the text-only one stands.
        $screenshot = $this->screenshot($page, $space, $goal, look: true);

        return $screenshot === null ? $first : $this->decide($page, $space, $goal, $history, $pending, $screenshot)->after($first);
    }

    /**
     * Asked whenever the screenshot is sent and read only when DONE is chosen: which decision ends
     * the run is not known until the answers arrive, and the image is in the request either way.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function screenQuestion(string $goal, ?Image $screenshot): array
    {
        if ($screenshot === null) {
            return [];
        }

        return ['screen_done' => [
            'type' => 'noul',
            'instructions' => Questions::instructions($goal, [Prompts::SCREEN, Prompts::SCREEN_DONE]),
            'criteria' => ['true' => Prompts::SCREEN_DONE_TRUE, 'false' => Prompts::SCREEN_DONE_FALSE],
        ]];
    }

    /**
     * A drag with nowhere to end is not an operation.
     */
    private static function dragsWithZones(ActionSpace $space): ActionSpace
    {
        return $space->zones === [] && isset($space->targets['DRAG']) ? $space->withoutOperation('DRAG') : $space;
    }

    /**
     * Where a drag ends is asked as its own head, used only when DRAG is the operation chosen.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function dropQuestion(string $goal, ActionSpace $space): array
    {
        if (! isset($space->targets['DRAG'])) {
            return [];
        }
        $criteria = [];
        foreach ($space->zones as $index => $zone) {
            $criteria[(string) $index] = Questions::describe((string) $index, $zone);
        }

        return ['drop_zone' => Questions::choice($criteria, Questions::instructions($goal, [Prompts::NEXT_ACTION, Prompts::DROP], 'DRAG'))];
    }

    /**
     * @return array<string, string>
     */
    private static function operations(ActionSpace $space): array
    {
        $labels = ['CLICK' => Prompts::OPERATION_CLICK, 'TYPE_TEXT' => Prompts::OPERATION_TYPE_TEXT, 'SELECT' => Prompts::OPERATION_SELECT, 'DRAG' => Prompts::OPERATION_DRAG];
        $operations = array_intersect_key($labels, $space->targets);
        foreach ($space->controls as $name => $control) {
            $operations[$name] = $control->label;
        }

        return $operations + ['DONE' => Prompts::OPERATION_DONE, 'BLOCKED' => Prompts::OPERATION_BLOCKED];
    }

    private static function noul(mixed $answer): ?float
    {
        $value = is_array($answer) ? ($answer['noul'] ?? null) : null;

        return (is_int($value) || is_float($value)) && $value >= 0 && $value <= 1 ? (float) $value : null;
    }

    /**
     * @param  list<Step>  $history
     * @param  list<string>  $pending
     */
    private function decide(Observation $page, ActionSpace $space, string $goal, array $history, array $pending, ?Image $screenshot): Decision
    {
        $operations = self::operations($space);
        // Read-only runs and the resubmit guard leave controls on the page that cannot be chosen;
        // without this the model reads a goal about seeing one as blocked.
        $notes = array_filter($space->elements, fn (array $e) => ($e['available'] ?? true) === false) === [] ? [] : [Prompts::UNAVAILABLE];
        if ($screenshot !== null) {
            $notes[] = Prompts::SCREEN;
        }
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
            + self::dropQuestion($goal, $space)
            + $this->plans->questions($pending, $operations, $space->targets, $settled, $notes)
            + self::screenQuestion($goal, $screenshot);
        $state = [
            'page' => ['url' => $page->url, 'title' => $page->title, 'text' => $page->text],
            'elements' => $space->elements,
            'recent_actions' => array_map(fn (Step $s) => $s->recent(), array_slice($history, -self::RECENT_STEPS)),
        ] + ($this->after?->report($page->url) ?? []) + ($screenshot === null ? [] : ['screen' => $screenshot]);
        $started = hrtime(true);
        $result = $this->client->ask($state, $questions, images: $screenshot !== null);

        return $this->decision($result['answers'], $space, $operations, $settled, $pending, [
            'model' => $result['model'],
            'usage' => $result['usage'],
            'latency' => (int) round((hrtime(true) - $started) / 1e6),
            'screenshot' => $screenshot !== null,
        ]);
    }

    /**
     * The marked screenshot for this decision, when {@see Looking} wants one and it can be taken
     * and fitted to the API's limits. $look is true for a decision being asked again.
     */
    private function screenshot(Observation $page, ActionSpace $space, string $goal, bool $look): ?Image
    {
        if ($this->screen === null || ! Looking::wanted($this->config->images, $look, $page->actions, $goal)) {
            return null;
        }
        $jpeg = $this->screen->jpeg();
        $marked = $jpeg === null ? null : MarkedScreenshot::of($jpeg, $page->viewportWidth, $space->targets);

        return $marked === null ? null : Image::fromBytes($marked);
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, string>  $operations
     * @param  array<string, string>  $settled
     * @param  list<string>  $pending
     * @param  array{model: string, usage: array<string, mixed>, latency: int, screenshot: bool}  $call
     */
    private function decision(array $answers, ActionSpace $space, array $operations, array $settled, array $pending, array $call): Decision
    {
        $answer = Choices::validate($answers['operation'] ?? null, array_map('strval', array_keys($operations)));
        $operation = $answer['choice'];
        [$choice, $target, $probabilities] = $this->target($answers, $space, $operation, $answer, $settled);
        $drop = $operation === 'DRAG'
            ? $space->zones[Choices::validate($answers['drop_zone'] ?? null, array_map('strval', array_keys($space->zones)))['choice']]->id
            : null;

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
            drop: $drop,
            screenshot: $call['screenshot'],
            screenDone: self::noul($answers['screen_done'] ?? null),
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
