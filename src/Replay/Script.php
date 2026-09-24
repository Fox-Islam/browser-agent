<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Replay;

use InvalidArgumentException;
use Phox\BrowserAgent\Agent\RunState;
use Phox\BrowserAgent\Agent\Step;
use Phox\BrowserAgent\Decision\ReadOnlyActions;

/**
 * A run's actions in a form a replay can take. Controls are named by label and kind, never by
 * element id: a node id identifies nothing on a page loaded again. Probabilities, element tables
 * and costs are left out; what remains is what was done.
 */
final class Script
{
    public const int VERSION = 1;

    /**
     * @return array{version: int, name: string|null, url: string|null, goal: string, steps: list<array{kind: string, label: string, text?: string, submits?: true}>}
     */
    public static function of(RunState $state, ?string $name = null): array
    {
        $steps = array_map(fn (Step $step): array => array_filter(
            ['kind' => $step->kind, 'label' => $step->label, 'text' => $step->text, 'submits' => $step->submits ?: null],
            fn ($value) => $value !== null,
        ), $state->history);

        return [
            'version' => self::VERSION,
            'name' => $name,
            // A run started on a page it was handed has no url of its own; the first page it acted
            // on stands in.
            'url' => $state->url ?? ($state->history[0]->url ?? null),
            'goal' => $state->goal,
            'steps' => $steps,
        ];
    }

    /**
     * A script checked well enough to fail before a browser is opened.
     *
     * @param  array<string, mixed>  $script
     * @return array<string, mixed>
     */
    public static function validate(array $script): array
    {
        $version = $script['version'] ?? null;

        return match (true) {
            $version !== self::VERSION => throw new InvalidArgumentException('Script version ' . json_encode($version) . ' is not ' . self::VERSION),
            ! is_string($script['url'] ?? null) || $script['url'] === '' => throw new InvalidArgumentException('Script has no url to start from'),
            ! is_array($script['steps'] ?? null) => throw new InvalidArgumentException('Script has no steps'),
            default => $script,
        };
    }

    /**
     * Whether replaying the script twice would send anything twice: it types, selects or submits.
     *
     * @param  array<string, mixed>  $script
     */
    public static function mutates(array $script): bool
    {
        foreach ($script['steps'] ?? [] as $step) {
            $kind = $step['kind'] ?? null;
            $submits = ($step['submits'] ?? false) === true || ReadOnlyActions::submits((string) ($step['label'] ?? ''));
            if (in_array($kind, ['fill', 'select'], true) || ($kind === 'click' && $submits)) {
                return true;
            }
        }

        return false;
    }
}
