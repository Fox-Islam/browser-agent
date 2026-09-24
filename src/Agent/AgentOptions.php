<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Closure;
use InvalidArgumentException;

/**
 * What a run is for and the limits a caller sets on it.
 */
final readonly class AgentOptions
{
    public const int MAX_STEPS = 60;

    /** @var list<string> */
    public array $goals;

    /** @var list<array{query: string, after: int}> each asked once `after` goals are satisfied, or at the end */
    public array $queries;

    /**
     * $goals are things to do, in order. A ['query' => js] entry among them is asked once the goals
     * before it are satisfied, or when the run stops; $goals is empty when the run only asks
     * queries. $queries are asked when the run stops. $readOnly withholds typing, selecting and
     * submitting from the action space, and is on unless turned off, so a caller that forgets it
     * cannot write to a site. $reuseHeld acts on a decision taken earlier for a later goal when it
     * applies to the current page.
     *
     * @param  list<string|array{query: string}>  $goals
     * @param  list<string>  $queries
     * @param  bool  $trackPlan  asks about each goal separately instead of joining them into one
     * @param  list<string>  $allowedHosts  hosts the run may visit, matched on suffix; empty allows all
     * @param  int  $maxSteps  most actions a run may take before it stops at its budget
     * @param  Closure(RunState): void|null  $heartbeat  called once per step
     */
    public function __construct(
        array $goals,
        array $queries = [],
        public bool $trackPlan = false,
        public bool $reuseHeld = true,
        public bool $readOnly = true,
        public array $allowedHosts = [],
        public int $maxSteps = self::MAX_STEPS,
        public ?Closure $heartbeat = null,
        public SettleTimings $timings = new SettleTimings,
    ) {
        $wanted = $asked = [];
        foreach ($goals as $goal) {
            if (is_array($goal) && isset($goal['query'])) {
                $asked[] = ['query' => $goal['query'], 'after' => count($wanted)];
            } elseif (is_string($goal) && trim($goal) !== '') {
                $wanted[] = trim($goal);
            }
        }
        if ($wanted === [] && $asked === [] && $queries === []) {
            throw new InvalidArgumentException('Supply a goal or a query');
        }
        $this->goals = $wanted;
        $this->queries = [...$asked, ...array_map(fn (string $q) => ['query' => $q, 'after' => count($wanted)], $queries)];
    }
}
