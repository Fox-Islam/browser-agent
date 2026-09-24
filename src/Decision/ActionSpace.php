<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Reader\Action;

/**
 * The observed controls as the decision model sees them: one index per element, however many
 * operations it supports, and per operation the targets it may be applied to. A select's options
 * are targets of their own, indexed under their element. An element whose actions are all
 * unavailable is listed with available: false and no operations, and is the target of nothing.
 */
final readonly class ActionSpace
{
    public const array OPERATIONS = ['click' => 'CLICK', 'fill' => 'TYPE_TEXT', 'select' => 'SELECT'];

    /**
     * @param  list<array<string, mixed>>  $elements  the element table sent to the model
     * @param  array<string, array<string, Action>>  $targets  operation => target index => action
     * @param  array<string, Action>  $controls  SCROLL_DOWN, SCROLL_UP and WAIT
     */
    private function __construct(
        public array $elements,
        public array $targets,
        public array $controls,
    ) {}

    /**
     * @param  list<Action>  $actions
     */
    public static function of(array $actions): self
    {
        $elements = $targets = $controls = $indices = [];
        foreach ($actions as $action) {
            $operation = self::OPERATIONS[$action->kind] ?? null;
            if ($operation === null) {
                $controls[mb_strtoupper($action->id)] = $action;

                continue;
            }
            $index = $indices[$action->node] ??= (string) (count($elements) + 1);
            $position = (int) $index - 1;
            $elements[$position] ??= self::element($index, $action);
            if (! $action->available) {
                continue;
            }
            unset($elements[$position]['available']);
            if (! in_array($operation, $elements[$position]['operations'], true)) {
                $elements[$position]['operations'][] = $operation;
            }
            $target = $index;
            if ($action->kind === 'select') {
                $target = $index . ':' . (count($elements[$position]['options']) + 1);
                $elements[$position]['options'][] = ['index' => $target, 'label' => $action->label, 'value' => $action->value];
            }
            $targets[$operation][$target] = $action;
        }

        return new self(array_values($elements), $targets, $controls);
    }

    /**
     * The same space without the targets named by label and kind. A control chosen repeatedly
     * without moving the page is not going to move it this time; withholding it forces the
     * next-best answer. Withholding everything would strand the run, so then nothing is withheld.
     *
     * @param  list<array{string, string}>  $suppressed  label and kind pairs
     */
    public function without(array $suppressed): self
    {
        $targets = [];
        foreach ($this->targets as $operation => $candidates) {
            $kept = array_filter($candidates, fn (Action $a) => ! in_array([$a->label, $a->kind], $suppressed, true));
            if ($kept !== []) {
                $targets[$operation] = $kept;
            }
        }

        return $targets === [] ? $this : new self($this->elements, $targets, $this->controls);
    }

    /**
     * @return array<string, mixed>
     */
    private static function element(string $index, Action $action): array
    {
        $element = array_filter([
            'role' => $action->role,
            'value' => $action->value,
            'checked' => $action->checked,
            'selected' => $action->selected,
            'expanded' => $action->expanded,
        ], fn ($value) => $value !== null);
        $element += ['index' => $index, 'label' => explode(' → ', $action->label)[0], 'operations' => []];
        if (! $action->available) {
            $element['available'] = false;
        }
        if ($action->kind === 'select') {
            $element['value'] = $action->currentValue ?? '';
            $element['options'] = [];
        }

        return $element;
    }
}
