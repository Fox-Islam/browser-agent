<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

/**
 * One entry of an observation's action list: an operation on a control, a drag source, a drop
 * zone, a scroll, or a wait.
 * Control fields are null or false on scroll and wait entries; states are null when the control
 * does not expose them. `group` is set only on a control with no name of its own, and is the
 * handle of the element holding it, so unnamed controls side by side share one, and `colour` is
 * the name of the colour it is filled with, when it has a fill of its own. `secret` marks a field
 * whose value is never read, a password or card field, typed only from the caller's secrets.
 */
final readonly class Action
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $label,
        public ?int $node = null,
        public ?string $role = null,
        public ?string $value = null,
        public ?string $currentValue = null,
        public ?int $omittedOptions = null,
        public ?string $format = null,
        public ?string $min = null,
        public ?string $max = null,
        public ?string $step = null,
        public ?string $checked = null,
        public ?string $selected = null,
        public ?string $expanded = null,
        public ?Rect $rect = null,
        public ?int $delta = null,
        public bool $submits = false,
        public ?int $form = null,
        public bool $available = true,
        public ?string $href = null,
        public bool $required = false,
        public bool $hover = false,
        public ?string $offscreen = null,
        public ?string $context = null,
        public ?int $group = null,
        public ?string $colour = null,
        public bool $secret = false,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     */
    public static function fromArray(array $action): self
    {
        return new self(
            id: $action['id'] ?? '',
            kind: $action['kind'],
            label: $action['label'],
            node: $action['node'] ?? null,
            role: $action['role'] ?? null,
            value: $action['value'] ?? null,
            currentValue: $action['current_value'] ?? null,
            omittedOptions: $action['omitted_options'] ?? null,
            format: $action['format'] ?? null,
            min: $action['min'] ?? null,
            max: $action['max'] ?? null,
            step: $action['step'] ?? null,
            checked: $action['checked'] ?? null,
            selected: $action['selected'] ?? null,
            expanded: $action['expanded'] ?? null,
            rect: isset($action['rect']) ? Rect::fromArray($action['rect']) : null,
            delta: $action['delta'] ?? null,
            submits: $action['submits'] ?? false,
            form: $action['form'] ?? null,
            href: $action['href'] ?? null,
            required: $action['required'] ?? false,
            hover: $action['hover'] ?? false,
            offscreen: $action['offscreen'] ?? null,
            context: $action['context'] ?? null,
            group: $action['group'] ?? null,
            colour: $action['colour'] ?? null,
            secret: $action['secret'] ?? false,
        );
    }

    /**
     * The same action, listed but not offered: the model sees the control exists and
     * cannot choose it.
     */
    public function unavailable(): self
    {
        $fields = get_object_vars($this);
        $fields['available'] = false;

        return new self(...$fields);
    }

    /**
     * A drop zone is where a drag ends, not something a user operates on its own.
     */
    public function operatesControl(): bool
    {
        return in_array($this->kind, ['click', 'fill', 'select', 'drag'], true);
    }
}
