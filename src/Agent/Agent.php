<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use GuzzleHttp\ClientInterface;
use Phox\BrowserAgent\Cdp\CdpSession;
use Phox\BrowserAgent\Decision\Decider;
use Phox\BrowserAgent\Decision\Decision;
use Phox\BrowserAgent\Decision\ModelClient;
use Phox\BrowserAgent\Decision\ModelConfig;
use Phox\BrowserAgent\Decision\ReadOnlyActions;
use Phox\BrowserAgent\Decision\Scrolling;
use Phox\BrowserAgent\Decision\TextHelper;
use Phox\BrowserAgent\Executor\ExecutionStatus;
use Phox\BrowserAgent\Executor\Executor;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReaderOptions;

/**
 * The decision loop: observe the page, choose an operation and a target in one model call, carry
 * it out, observe again. A decision is consumed before anything is done with it, and an executed
 * action is recorded before the page is observed, so no path repeats a click or a keystroke.
 */
final class Agent
{
    /**
     * A sub-goal reading at or above this is retired. On a measured flight-search run, satisfied
     * steps read 0.92-0.97 and unsatisfied ones 0.02-0.09.
     */
    public const float PLAN_SATISFIED = 0.8;

    /**
     * A run that gives up with this fraction of the page below the viewport is stuck, not
     * not_found.
     */
    public const float UNSEEN_ENOUGH = 0.15;

    /** Actions in a row that moved nothing, waits aside, that end the run blocked. */
    public const int NO_PROGRESS = 3;

    /** Set when a run starts; null before. */
    private ?RunState $state = null;

    private HeldDecisions $held;

    private SubmitGuard $submits;

    private FieldValues $values;

    private PageQuestions $questions;

    private ?float $unfreshSince = null;

    /** The page a decision was just confirmed against, so acting on it needs no second look. */
    private ?string $verified = null;

    private float $started = 0.0;

    public function __construct(
        private readonly CdpSession $cdp,
        private readonly PageReader $reader,
        private readonly Executor $executor,
        private readonly Decider $decider,
        TextHelper $text,
        private readonly AgentOptions $options,
        private readonly Faults $faults = new Faults,
    ) {
        $this->held = new HeldDecisions;
        $this->submits = new SubmitGuard;
        $this->values = new FieldValues($text);
        $this->questions = new PageQuestions($reader, $options->queries);
    }

    /**
     * An agent whose model calls all share $http.
     */
    public static function make(CdpSession $cdp, ModelConfig $config, ClientInterface $http, AgentOptions $options, ReaderOptions $reading = new ReaderOptions): self
    {
        $client = new ModelClient($http, $config->timeout);
        $reader = new PageReader($cdp, $reading);

        return new self($cdp, $reader, new Executor($cdp, $reader, waitSeconds: 0.1), new Decider($client, $config), new TextHelper($client, $config), $options);
    }

    /**
     * The run so far, including after an exception: a caller that stops a run by throwing from its
     * heartbeat reports what the run did before it stopped. Null before the run starts.
     */
    public function state(): ?RunState
    {
        return $this->state;
    }

    /**
     * Runs until the goal is done, blocked, over budget or off the allowed hosts. With a url the
     * page goes there first; without one the run starts on the page the session shows.
     */
    public function run(?string $url = null): RunState
    {
        $this->faults->watch($this->cdp);
        $settler = $this->settler();
        if ($url !== null) {
            $settler->navigate($url);
        }
        $plan = $this->options->trackPlan && count($this->options->goals) > 1 ? $this->options->goals : [implode("\n", $this->options->goals)];
        $this->state = new RunState($url, implode("\n", $plan), $plan, $this->visible($settler->readable()));
        $this->started = microtime(true);
        if ($this->options->goals === []) {
            // Only queries: nothing to decide, so the run is done once they are answered.
            $this->stop('done');
            $this->finishStep();
        }
        while (! $this->state->stopped()) {
            $this->tick();
            $this->finishStep();
            if ($this->options->heartbeat !== null) {
                ($this->options->heartbeat)($this->state);
            }
        }

        return $this->state;
    }

    private function tick(): void
    {
        try {
            $this->predict();
            if (! $this->state->stopped()) {
                $this->act();
            }
        } catch (StalePage $stale) {
            $this->state->stale[] = ['after_step' => count($this->state->history), 'reason' => mb_substr($stale->getMessage(), 0, 200)];
            $this->state->decision = null;
            $this->state->status = 'ready';
            $this->state->page = $this->visible($this->settler()->readable());
        }
    }

    private function predict(): void
    {
        $state = $this->state;
        $this->verified = null;
        $outstanding = $this->outstanding();
        $reused = $this->options->reuseHeld ? $this->held->reuse($state, $outstanding) : null;
        if ($reused !== null) {
            $state->decision = $reused;
            $state->status = 'predicted';

            return;
        }
        $state->decision = $this->decide($outstanding);
        if ($state->decision === null) {
            $state->status = 'budget';

            return;
        }
        $this->readPlan($outstanding, $state->decision);
        $this->values->prefetch($state, $this->held->held);
        $state->decisions[] = $state->decision;
        $state->status = 'predicted';
    }

    /**
     * A decision about the page as it is. The request goes out as soon as the page is readable,
     * and the answer is kept when the page is unchanged by the time it arrives: a page that held
     * still for the length of a decision has settled. One that did not is waited on until it goes
     * quiet, then asked about again, so a page that keeps changing costs one wait instead of a
     * request per change. Past the restless limit the answer is kept anyway, because a clock or a
     * progress figure never holds still; the executor's page-key and guard checks stand
     * between it and the page. Null once the run has spent its decision budget.
     *
     * @param  list<int>  $outstanding
     */
    private function decide(array $outstanding): ?Decision
    {
        $state = $this->state;
        $pending = array_map(fn (int $i) => $state->plan[$i], $outstanding);
        while ($state->decisionCalls < $this->options->maxSteps * 2) {
            $asked = $state->page;
            $state->decisionCalls++;
            $decision = $this->decider->choose($asked, $state->goal, $state->history, $pending, Fixation::of($this->state->history));
            if ($this->reader->read()?->fingerprint === $asked->fingerprint || $this->restless() || $this->waitedOutRestless()) {
                $this->unfreshSince = null;
                $this->verified = $asked->fingerprint;

                return $decision;
            }
            $state->page = $this->visible($this->settler()->readable());
        }

        return null;
    }

    /**
     * Waits in the page for it to go quiet, no longer than the restless limit allows, and returns
     * whether the limit ran out.
     */
    private function waitedOutRestless(): bool
    {
        $left = $this->options->timings->restless - (microtime(true) - (float) $this->unfreshSince);
        $this->reader->holdsStill($this->options->timings->stillMs(), max(0.05, $left));

        return $this->restless();
    }

    /**
     * Starts the clock on a page that has stopped matching, and returns whether it has run out.
     */
    private function restless(): bool
    {
        $this->unfreshSince ??= microtime(true);

        return microtime(true) - $this->unfreshSince >= $this->options->timings->restless;
    }

    private function act(): void
    {
        $state = $this->state;
        $decision = $state->decision;
        $page = $state->page;
        // Consumed before any mutation or model call, so nothing can act on it twice.
        $state->decision = null;
        if ($decision === null) {
            return;
        }
        if ($this->options->trackPlan && count($state->plan) > 1 && count($state->planSatisfied) === count($state->plan)) {
            // Several narrow checks agreeing is firmer evidence than one broad DONE.
            $this->stop('done');

            return;
        }
        $action = $page->action($decision->choice);
        match (true) {
            $action === null && $decision->choice === 'DONE' && $this->unsatisfied() !== [] => $this->doubleCheck($page),
            $action === null => $this->conclude($decision->choice, $page),
            count($state->history) >= $this->options->maxSteps => $this->stop('budget'),
            default => $this->execute($decision, $action, $page),
        };
    }

    /**
     * DONE arrived while a tracked sub-goal reads unsatisfied. Satisfaction readings are too noisy
     * to overrule DONE, so one more decision is taken with the first unsatisfied sub-goal as the
     * goal. A second DONE stops the run done, recording the sub-goals never confirmed; anything
     * else is acted on.
     */
    private function doubleCheck(Observation $page): void
    {
        if (! $this->settledOrRestless($page)) {
            throw new StalePage('Page changed since the decision. Choose again.');
        }
        $state = $this->state;
        $state->decisionCalls++;
        $decision = $this->decider->choose($page, $state->plan[$this->unsatisfied()[0]], $state->history, [], Fixation::of($state->history));
        $state->decisions[] = $decision;
        $action = $page->action($decision->choice);
        if ($decision->choice === 'DONE') {
            $state->unconfirmed = $this->unsatisfied();
        }
        $action === null ? $this->conclude($decision->choice, $page) : $this->execute($decision, $action, $page);
    }

    /**
     * @return list<int>
     */
    private function unsatisfied(): array
    {
        return $this->options->trackPlan && count($this->state->plan) > 1
            ? array_values(array_diff(array_keys($this->state->plan), $this->state->planSatisfied))
            : [];
    }

    private function conclude(string $choice, Observation $page): void
    {
        if (! $this->settledOrRestless($page)) {
            throw new StalePage('Page changed since the decision. Choose again.');
        }
        if ($choice === 'DONE') {
            $this->stop('done');

            return;
        }
        // A page read to the bottom without the thing on it is a different answer from one
        // abandoned part way down, and a planner can only act on the difference.
        $this->state->reason = Scrolling::unseen($page) >= self::UNSEEN_ENOUGH ? 'stuck' : 'not_found';
        $this->stop('blocked');
    }

    private function execute(Decision $decision, Action $action, Observation $page): void
    {
        [$text, $spent] = $action->kind === 'fill' ? $this->fillValue($decision, $action, $page) : [null, null];
        $execution = $this->executor->execute($page, $action, $text);
        if ($execution->status === ExecutionStatus::Stale) {
            throw new StalePage((string) $execution->reason);
        }
        $this->values->spent($action->label);
        if ($execution->status === ExecutionStatus::Rejected) {
            // Refused before anything was sent; the next decision starts from the same page.
            throw new StalePage((string) $execution->reason);
        }
        $step = new Step(
            number: count($this->state->history) + 1,
            label: $action->label,
            kind: $action->kind,
            choice: $decision->choice,
            operation: $decision->operation,
            probability: $decision->probability(),
            confidence: $decision->confidence,
            text: $text,
            url: $page->url,
            elapsedMs: $this->elapsed(),
            decisionLatencyMs: $decision->latencyMs,
            textModel: $spent['model'] ?? null,
            textLatencyMs: $spent['latency_ms'] ?? 0,
            usage: $decision->usage,
            decidedBy: $decision->model,
            reusedFor: $decision->reusedFor,
            note: $execution->status === ExecutionStatus::Altered ? $execution->reason : null,
            submits: $action->submits,
            node: $action->node,
        );
        $this->state->history[] = $step;
        $this->submits->record($action, $page, $text);
        $this->observeAfter($step, $page);
    }

    /**
     * @return array{string, array<string, mixed>|null}
     */
    private function fillValue(Decision $decision, Action $action, Observation $page): array
    {
        if (! $this->settledOrRestless($page)) {
            throw new StalePage('Page changed before text generation. Choose again.');
        }
        // A held decision was taken for one sub-goal, so that is what the field is for. The whole
        // task invites a value inferred from the wrong part of it: asked for an email under a
        // five-line goal with "Ada" and "Lovelace" just typed, the helper answered "Ada Lovelace".
        $goal = $decision->reusedFor !== null ? $this->state->plan[$decision->reusedFor] : $this->state->goal;

        return $this->values->valueFor($this->state, $action, $goal);
    }

    private function observeAfter(Step $step, Observation $before): void
    {
        $state = $this->state;
        $state->page = $this->visible($this->settler()->readable($before, $step->kind));
        $step->pageChanged = $state->page->fingerprint !== $before->fingerprint;
        $step->result = $state->page->fingerprint;
        $step->url = $state->page->url;
        $step->scroll = ['y' => $state->page->scrollY, 'height' => $state->page->scrollHeight, 'view' => $state->page->viewportHeight];
        $step->elapsedMs = $this->elapsed();
        if ($this->offSite($state->page->url)) {
            // Stopped where it went: the refused address tells the caller which link left the site.
            $state->refusedUrl = $state->page->url;
            $this->stop('off_site');

            return;
        }
        $recent = array_slice($state->history, -self::NO_PROGRESS);
        $stuck = count($recent) === self::NO_PROGRESS && array_filter($recent, fn (Step $s) => $s->pageChanged !== false || $s->kind === 'wait') === [];
        $state->status = $stuck ? 'blocked' : 'ready';
    }

    /**
     * @param  list<int>  $outstanding
     */
    private function readPlan(array $outstanding, Decision $decision): void
    {
        foreach ($outstanding as $position => $index) {
            $satisfied = $decision->plan[$position]->satisfied ?? null;
            $this->state->satisfaction[$index] = $satisfied ?? $this->state->satisfaction[$index] ?? null;
            if ($satisfied !== null && $satisfied >= self::PLAN_SATISFIED && ! in_array($index, $this->state->planSatisfied, true)) {
                $this->state->planSatisfied[] = $index;
            }
        }
        sort($this->state->planSatisfied);
        $this->held->update($outstanding, $decision->plan, $this->state->planSatisfied);
    }

    /**
     * @return list<int>
     */
    private function outstanding(): array
    {
        if (! $this->options->trackPlan || count($this->state->plan) < 2) {
            return [];
        }

        return array_values(array_diff(array_keys($this->state->plan), $this->state->planSatisfied));
    }

    /**
     * Whether the page matches the observation, keeping the clock on how long it has not. Past the
     * restless limit a page that will not hold still is acted on anyway: a clock or a progress
     * figure never passes this check. The executor's page-key and guard checks apply regardless.
     */
    private function settledOrRestless(Observation $page): bool
    {
        if ($page->fingerprint === $this->verified || $this->settler()->fresh($page)) {
            $this->unfreshSince = null;

            return true;
        }

        return $this->restless();
    }

    private function offSite(string $url): bool
    {
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_map(fn (string $h) => mb_strtolower(ltrim($h, '.')), $this->options->allowedHosts);

        return $allowed !== [] && array_filter($allowed, fn (string $name) => $host === $name || str_ends_with($host, ".{$name}")) === [];
    }

    /**
     * A read-only run is offered no way to write: typing, selecting and submitting are marked
     * unavailable in every observation it decides from, as are submits the resubmit guard holds.
     */
    private function visible(Observation $page): Observation
    {
        $page = $this->submits->mark($page);

        return $this->options->readOnly ? $page->withActions(ReadOnlyActions::mark($page->actions)) : $page;
    }

    private function finishStep(): void
    {
        $state = $this->state;
        $state->elapsedMs = $this->elapsed();
        // A stopped run asks every query it has left on the page it stopped on: a query is how a
        // caller inspects a page the agent could not finish on.
        $state->queries = $this->questions->due($state->stopped() ? PHP_INT_MAX : count($state->planSatisfied));
        $state->faults = $this->faults->diagnosis();
        if ($state->stopped() && $state->evidence === null) {
            // Every stopped run reports what the page was, not only the ones that found something.
            $state->evidence = (new Evidence($this->reader, $this->faults))->of($state);
        }
    }

    private function stop(string $status): void
    {
        $this->state->status = $status;
    }

    private function elapsed(): int
    {
        return (int) round((microtime(true) - $this->started) * 1000);
    }

    private function settler(): Settler
    {
        return new Settler($this->reader, $this->cdp, $this->options->timings);
    }
}
