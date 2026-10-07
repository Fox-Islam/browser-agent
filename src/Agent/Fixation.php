<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

/**
 * Controls chosen repeatedly in the recent past without progress, to be withheld from the next
 * decision. It catches a run choosing "Open Return" until the no-progress guard fired while the
 * fields it needed were never attempted.
 */
final class Fixation
{
    /** With WINDOW, this allows one ineffective retry, which is often legitimate. */
    public const int REPEATS = 2;

    public const int WINDOW = 3;

    /**
     * A step counts when it moved nothing, or when it left the page exactly as another step on the
     * same control did: a control that redraws the same result changes the page each time without
     * getting anywhere.
     *
     * A control refused as covered sent nothing and so left no step, and counts here through
     * $refused: label and kind pairs with how many times each was refused since the last step.
     *
     * @param  list<Step>  $history
     * @param  array<string, int>  $refused  json-encoded label and kind pair => refusals
     * @return list<array{string, string}> label and kind pairs
     */
    public static function of(array $history, array $refused = []): array
    {
        $counts = $refused;
        foreach (array_slice($history, -self::WINDOW) as $step) {
            if ($step->kind !== 'wait' && ($step->pageChanged === false || self::repeatsOutcome($step, $history))) {
                $key = json_encode([$step->label, $step->kind], JSON_THROW_ON_ERROR);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return array_values(array_map(
            fn (string $key) => json_decode($key, true),
            array_keys(array_filter($counts, fn (int $n) => $n >= self::REPEATS)),
        ));
    }

    /**
     * @param  list<Step>  $history
     */
    private static function repeatsOutcome(Step $step, array $history): bool
    {
        foreach ($history as $other) {
            $sameControl = $other !== $step && $other->label === $step->label && $other->kind === $step->kind && $other->node === $step->node;
            if ($sameControl && $step->result !== null && $other->result === $step->result) {
                return true;
            }
        }

        return false;
    }
}
