<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Decision\Decision;
use Phox\BrowserAgent\Reader\Observation;

/**
 * Everything a run knows about itself. Its lists are bounded by the step budget, the decision
 * budget or a fixed cap, and its page by the reader's limits; goals and queries are the caller's.
 */
final class RunState
{
    public const array STOPPED = ['done', 'blocked', 'budget', 'off_site'];

    public string $status = 'ready';

    /**
     * Why a blocked run stopped: not_found where the page was read to the end, stuck where part of
     * it never was.
     */
    public ?string $reason = null;

    public ?Decision $decision = null;

    /** @var list<Step> */
    public array $history = [];

    /** @var list<Decision> the decisions acted on */
    public array $decisions = [];

    /** Decision requests sent, including those whose page changed before the answer arrived. */
    public int $decisionCalls = 0;

    /**
     * Decisions the page moved under before they could be carried out, and why. Each costs a
     * decision request, so the decision budget bounds the list.
     *
     * @var list<array{after_step: int, reason: string}>
     */
    public array $stale = [];

    /** @var list<int> indices of the sub-goals read as satisfied */
    public array $planSatisfied = [];

    /** @var array<int, float|null> each tracked sub-goal's latest satisfaction reading */
    public array $satisfaction = [];

    /** @var list<int> sub-goals a done run stopped without reading as satisfied */
    public array $unconfirmed = [];

    /** @var list<array<string, mixed>> */
    public array $textCalls = [];

    /** @var array<string, mixed>|null */
    public ?array $evidence = null;

    public ?string $refusedUrl = null;

    /** @var list<array{value?: mixed, exception?: string}|null> */
    public array $queries = [];

    /** @var array<string, mixed> */
    public array $faults = [];

    public int $elapsedMs = 0;

    /**
     * @param  list<string>  $plan  the goals, one sub-goal each when tracked, else joined into one
     */
    public function __construct(
        public ?string $url,
        public string $goal,
        public array $plan,
        public Observation $page,
    ) {}

    public function stopped(): bool
    {
        return in_array($this->status, self::STOPPED, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'goal' => $this->goal,
            'plan' => $this->plan,
            'status' => $this->status,
            'reason' => $this->reason,
            'history' => array_map(fn (Step $s) => $s->toArray(), $this->history),
            'plan_satisfied' => $this->planSatisfied,
            'satisfaction' => $this->satisfaction,
            'unconfirmed' => $this->unconfirmed,
            'evidence' => $this->evidence,
            'refused_url' => $this->refusedUrl,
            'queries' => $this->queries,
            'faults' => $this->faults,
            'text_calls' => $this->textCalls,
            'decisions' => count($this->decisions),
            'decision_calls' => $this->decisionCalls,
            'stale' => $this->stale,
            'elapsed_ms' => $this->elapsedMs,
        ];
    }
}
