<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Replay;

use Phox\BrowserAgent\Agent\RunState;
use Phox\BrowserAgent\Reader\Action;

/**
 * What a run did, small enough to hand back to whatever sets its goals. A planner needs what was
 * attempted and where it got to, not what the page said: page text and markup are too large to
 * pass around, and an action id means nothing outside the observation that numbered it. Labels,
 * nodes, positions and the operation probabilities behind the last decision are kept; nothing grows
 * with the page.
 */
final class Report
{
    public const int CONTROLS = 25;

    public const int LABEL_LENGTH = 32;

    public const int CONTROL_LENGTH = 24;

    /** A url shows where a step went; Google Flights puts a whole search in one. */
    public const int URL_LENGTH = 90;

    /**
     * @return array<string, mixed>
     */
    public static function of(RunState $state, int $controls = self::CONTROLS): array
    {
        $page = $state->page;
        $last = $state->decisions === [] ? null : end($state->decisions);

        return [
            'url' => $state->url,
            'goals' => $state->plan,
            'status' => $state->status,
            'steps' => self::steps($state),
            'final' => [
                'url' => self::brief($page->url, self::URL_LENGTH),
                'title' => self::brief($page->title),
                'y' => $page->scrollY,
                'height' => $page->scrollHeight,
                'controls' => self::controls($page->actions, $controls),
            ],
            'operations' => array_map(fn (float $p) => round($p, 3), $last->operationProbabilities ?? []),
            'reason' => $state->reason,
            ...self::subGoals($state),
            'evidence' => $state->evidence,
            'refused_url' => $state->refusedUrl,
            'faults' => $state->faults,
            'queries' => $state->queries,
        ];
    }

    public static function brief(?string $text, int $length = self::LABEL_LENGTH): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($text) <= $length ? $text : mb_substr($text, 0, $length - 1) . '…';
    }

    /**
     * With sub-goals tracked, each one's latest satisfaction reading and the goals a done run
     * stopped without confirming.
     *
     * @return array{sub_goals?: list<array{goal: string, satisfaction: float|null, satisfied: bool}>, unconfirmed?: list<string>}
     */
    private static function subGoals(RunState $state): array
    {
        if (count($state->plan) < 2) {
            return [];
        }
        $goals = [];
        foreach ($state->plan as $index => $goal) {
            $reading = $state->satisfaction[$index] ?? null;
            $goals[] = [
                'goal' => $goal,
                'satisfaction' => $reading === null ? null : round($reading, 3),
                'satisfied' => in_array($index, $state->planSatisfied, true),
            ];
        }

        return ['sub_goals' => $goals, 'unconfirmed' => array_map(fn (int $i) => $state->plan[$i], $state->unconfirmed)];
    }

    /**
     * The page's controls with their nodes, so the caller can reach one in a query with el(node).
     * The Open click beside a fill is the same element again and is left out.
     *
     * @param  list<Action>  $actions
     * @return list<array{node: int|null, label: string}>
     */
    private static function controls(array $actions, int $limit): array
    {
        $fills = [];
        foreach ($actions as $action) {
            if ($action->kind === 'fill') {
                $fills["{$action->node}\0Open {$action->label}"] = true;
            }
        }
        $listed = array_filter(
            $actions,
            fn (Action $a) => $a->operatesControl() && ! ($a->kind === 'click' && isset($fills["{$a->node}\0{$a->label}"])),
        );

        return array_slice(array_map(
            fn (Action $a) => ['node' => $a->node, 'label' => self::brief($a->label, self::CONTROL_LENGTH)],
            array_values($listed),
        ), 0, $limit);
    }

    /**
     * A url and a page height repeat across most steps, so each is carried only when it differs
     * from the step before.
     *
     * @return list<array<string, mixed>>
     */
    private static function steps(RunState $state): array
    {
        $steps = [];
        $url = $state->url;
        $height = null;
        foreach ($state->history as $step) {
            $recorded = ['kind' => $step->kind, 'node' => $step->node, 'label' => self::brief($step->label), 'changed' => (bool) $step->pageChanged, 'y' => $step->scroll['y'] ?? null];
            if ($step->text !== null) {
                $recorded['text'] = self::brief($step->text);
            }
            if ($step->url !== $url) {
                $url = $step->url;
                $recorded['url'] = self::brief($url, self::URL_LENGTH);
            }
            if (($step->scroll['height'] ?? null) !== null && $step->scroll['height'] !== $height) {
                $height = $recorded['height'] = $step->scroll['height'];
            }
            $steps[] = $recorded;
        }

        return $steps;
    }
}
