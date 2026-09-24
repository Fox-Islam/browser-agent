<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

/**
 * An observation as text, for handing the page to a model as context. Deterministic: the same
 * observation always renders to the same text. The reader measures document mode against the same
 * rendering (reader/src/render.js) to fit max_document, so the two must agree character for
 * character; tests pin them together.
 */
final class TextRendering
{
    public static function of(Observation $observation): string
    {
        $out = "URL: {$observation->url}\nTitle: {$observation->title}\nOutline:\n";
        foreach ($observation->outline as $heading) {
            $out .= str_repeat('  ', max(0, $heading['level'] - 1)) . $heading['text'] . "\n";
        }
        $out .= "Text:\n" . ($observation->text === '' ? '' : "{$observation->text}\n");
        $out .= "Controls:\n";
        foreach (self::groups($observation->actions) as $group) {
            $out .= self::control($group);
        }
        $cut = $observation->truncated ?? [];
        if (array_filter($cut) !== []) {
            $out .= "Cut to fit: {$cut['outline']} headings, {$cut['text']} characters of text, {$cut['actions']} controls\n";
        }

        return $out;
    }

    /**
     * One group per control: a select's option actions render as one control, and the Open click
     * beside a fill is the same control again.
     *
     * @param  list<Action>  $actions
     * @return list<non-empty-list<Action>>
     */
    private static function groups(array $actions): array
    {
        $groups = [];
        foreach ($actions as $action) {
            $last = $groups === [] ? null : $groups[count($groups) - 1][0];
            if (! $action->operatesControl()) {
                continue;
            }
            $sameSelect = $action->kind === 'select' && $last?->kind === 'select' && self::selectKey($last) === self::selectKey($action);
            $openClick = $action->kind === 'click' && $last?->kind === 'fill' && $action->node === $last->node && $action->label === "Open {$last->label}";
            if ($sameSelect) {
                $groups[count($groups) - 1][] = $action;
            } elseif (! $openClick) {
                $groups[] = [$action];
            }
        }

        return $groups;
    }

    /**
     * @param  non-empty-list<Action>  $group
     */
    private static function control(array $group): string
    {
        $first = $group[0];
        $select = $first->kind === 'select';
        $line = "- [{$first->node}] {$first->role}: " . ($select ? self::option($first->label)[0] : $first->label);
        $line .= $first->kind === 'click' ? '' : " [{$first->kind}]";
        // A button named from its value would say it twice; a field always shows what it holds.
        $echoesLabel = $first->role === 'button' && $first->value === $first->label;
        $line .= ! $select && ($first->value ?? '') !== '' && ! $echoesLabel ? " value={$first->value}" : '';
        $line .= $select && ($first->currentValue ?? '') !== '' ? " selected={$first->currentValue}" : '';
        $line .= self::flags($first) . "\n";
        if (! $select) {
            return $line;
        }
        $more = ($first->omittedOptions ?? 0) > 0 ? " (+{$first->omittedOptions} more)" : '';

        return $line . '  options: ' . implode(', ', array_map(fn (Action $a) => self::option($a->label)[1], $group)) . "{$more}\n";
    }

    private static function flags(Action $action): string
    {
        return ($action->required ? ' (required)' : '')
            . match ($action->checked) {
                'true' => ' (checked)', 'false' => ' (unchecked)', 'mixed' => ' (mixed)', default => '',
            }
        . match ($action->expanded) {
            'true' => ' (expanded)', 'false' => ' (collapsed)', default => '',
        }
        . ($action->submits ? ' (submits)' : '')
        . (($action->format ?? '') !== '' ? " format={$action->format}" : '')
        . (($action->href ?? '') !== '' ? " -> {$action->href}" : '');
    }

    private static function selectKey(Action $action): string
    {
        return ($action->node ?? '') . "\0" . self::option($action->label)[0] . "\0" . ($action->currentValue ?? '');
    }

    /**
     * A select action is labelled "<label> → <option label>".
     *
     * @return array{string, string}
     */
    private static function option(string $label): array
    {
        $at = mb_strrpos($label, ' → ');

        return $at === false ? [$label, ''] : [mb_substr($label, 0, $at), mb_substr($label, $at + 3)];
    }
}
