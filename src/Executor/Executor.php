<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Executor;

use InvalidArgumentException;
use Phox\BrowserAgent\Cdp\CdpSession;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReadingMode;
use Phox\BrowserAgent\Reader\Rect;

/**
 * Carries out one action from an observation. Before a click, fill or select it confirms the page
 * key, the control's guard and the control at the click point are as observed; any difference is a
 * stale page and nothing is done. No step is ever retried: a repeated click or keystroke can
 * resubmit a form on a live site.
 */
final readonly class Executor
{
    private const int CTRL = 2;

    /** How long a control shown under the pointer has to fade in once the pointer is over it. */
    private const int HOVER_MS = 600;

    public function __construct(
        private CdpSession $cdp,
        private PageReader $reader,
        private float $waitSeconds = 1.0,
        private Drag $drag = new Drag,
    ) {}

    /**
     * @param  string|null  $text  what to fill; required for fill actions
     * @param  Action|null  $drop  where a drag ends; required for drag actions
     */
    public function execute(Observation $observation, Action $action, ?string $text = null, ?Action $drop = null): Execution
    {
        if ($observation->mode === ReadingMode::Document) {
            throw new InvalidArgumentException('A document-mode observation is for reading, not acting on');
        }

        return match ($action->kind) {
            'scroll' => $this->scroll($observation, (int) $action->delta),
            'wait' => $this->wait(),
            'click', 'fill', 'select', 'drag' => $this->operate($observation, $action, $text, $drop),
            default => throw new InvalidArgumentException("Unknown action kind {$action->kind}"),
        };
    }

    private static function assertUsable(Action $action, ?string $text, ?Action $drop): void
    {
        if (! $action->available) {
            throw new InvalidArgumentException("Action {$action->id} is not available to this run");
        }
        if ($action->kind === 'fill' && $text === null) {
            throw new InvalidArgumentException("Fill action {$action->id} needs a value");
        }
        if ($action->kind === 'drag' && $drop?->kind !== 'drop') {
            throw new InvalidArgumentException("Drag action {$action->id} needs a drop zone");
        }
    }

    /**
     * @return array{float, float}
     */
    private static function centre(Rect $rect): array
    {
        return [$rect->x + $rect->w / 2, $rect->y + $rect->h / 2];
    }

    private function operate(Observation $observation, Action $action, ?string $text, ?Action $drop): Execution
    {
        self::assertUsable($action, $text, $drop);
        $refusal = $action->kind === 'fill' && $action->format !== null
            ? ValueFormat::check($action->format, (string) $text, $action->min, $action->max, $action->step)
            : null;
        $stale = $refusal === null ? $this->changed($observation, $action, $drop) : null;

        return match (true) {
            $refusal !== null => Execution::rejected($refusal),
            $stale !== null => Execution::stale($stale),
            default => $this->reach($action, (string) $text, $drop),
        };
    }

    /**
     * Brings the control into view when it sits out of view in a scrolling panel, makes sure
     * nothing else is at the point it is pressed (and at the drop zone, for a drag), then acts.
     */
    private function reach(Action $action, string $text, ?Action $drop): Execution
    {
        $point = $this->pointOf($action);
        $refusal = $point === null ? 'the control would not come into view' : $this->obstruction($action, $point);
        $zone = $refusal === null && $drop !== null ? $this->obstruction($drop, self::centre($drop->rect)) : null;

        return match (true) {
            $refusal !== null => Execution::covered($refusal),
            $zone !== null => Execution::covered("the drop zone: {$zone}"),
            default => $this->perform($action, $text, $point, $drop),
        };
    }

    /**
     * @return array{float, float}|null
     */
    private function pointOf(Action $action): ?array
    {
        $rect = $action->offscreen !== null ? $this->reader->reveal((int) $action->node) : $action->rect;

        return $rect === null ? null : self::centre($rect);
    }

    /**
     * Why the control cannot be pressed at $point, or null when it can. A control shown only
     * under the pointer has to appear once the pointer is over it.
     *
     * @param  array{float, float}  $point
     */
    private function obstruction(Action $action, array $point): ?string
    {
        if ($action->hover) {
            $this->mouse('mouseMoved', $point[0], $point[1], button: 'none', buttons: 0);
            if (! $this->reader->opaque((int) $action->node, self::HOVER_MS)) {
                return 'the control did not appear under the pointer';
            }
        }
        $blocker = $this->reader->blocker((int) $action->node, $point[0], $point[1]);

        return $blocker === null ? null : "in the way: {$blocker}";
    }

    /**
     * @param  array{float, float}  $point
     */
    private function perform(Action $action, string $text, array $point, ?Action $drop): Execution
    {
        $altered = match (true) {
            $action->kind === 'click' => $this->click($point),
            $action->kind === 'drag' && $drop !== null => $this->dragTo($point, $drop),
            $action->kind === 'fill' && $action->format === null => $this->type($point, $text),
            $action->kind === 'select' => $this->set($action, (string) $action->value),
            default => $this->set($action, $text),
        };

        return $altered === null ? Execution::done() : Execution::altered($altered);
    }

    /**
     * @param  array{float, float}  $point
     */
    private function dragTo(array $point, Action $drop): null
    {
        $this->drag->perform($this->cdp, $point, self::centre($drop->rect));

        return null;
    }

    /**
     * The page clears or clamps a value it does not accept without saying so; comparing what the
     * element holds afterwards is how that is noticed. A colour is held in lower case and a number
     * in its shortest form, so those compare by meaning.
     */
    private function set(Action $action, string $value): ?string
    {
        $held = $this->reader->setValue((int) $action->node, $value);
        $same = match ($action->format) {
            '#rrggbb' => $held !== null && mb_strtolower($held) === mb_strtolower($value),
            'number' => $held !== null && is_numeric($held) && is_numeric($value) && (float) $held === (float) $value,
            default => $held === $value,
        };

        return match (true) {
            $held === null => 'the control left the document',
            $same => null,
            default => "the page holds \"{$held}\" instead of \"{$value}\"",
        };
    }

    private function changed(Observation $observation, Action $action, ?Action $drop): ?string
    {
        $node = (int) $action->node;

        return match (true) {
            $this->reader->pageKey() !== $observation->pageKey => 'the page changed',
            $this->reader->guard($node) !== $observation->guard($node) => 'the control changed',
            $drop !== null && $this->reader->guard((int) $drop->node) !== $observation->guard((int) $drop->node) => 'the drop zone changed',
            default => null,
        };
    }

    /**
     * @param  array{float, float}  $point
     */
    private function click(array $point): null
    {
        [$x, $y] = $point;
        $this->mouse('mouseMoved', $x, $y, button: 'none', buttons: 0);
        $this->mouse('mousePressed', $x, $y, button: 'left', buttons: 1);
        $this->mouse('mouseReleased', $x, $y, button: 'left', buttons: 0);

        return null;
    }

    /**
     * Select-all goes through Chrome's editing command instead of a platform shortcut, so it works
     * whatever OS the browser reports.
     */
    /**
     * @param  array{float, float}  $point
     */
    private function type(array $point, string $text): null
    {
        $this->click($point);
        $this->key(['key' => 'a', 'code' => 'KeyA', 'windowsVirtualKeyCode' => 65, 'modifiers' => self::CTRL, 'commands' => ['selectAll']]);
        if ($text === '') {
            $this->key(['key' => 'Backspace', 'code' => 'Backspace', 'windowsVirtualKeyCode' => 8]);
        } else {
            $this->cdp->send('Input.insertText', ['text' => $text]);
        }

        return null;
    }

    /**
     * A wheel event instead of window.scrollBy honours the page's smooth scrolling, scroll snapping
     * and scroll containers.
     */
    private function scroll(Observation $observation, int $delta): Execution
    {
        $this->cdp->send('Input.dispatchMouseEvent', [
            'type' => 'mouseWheel',
            'x' => $observation->viewportWidth / 2,
            'y' => $observation->viewportHeight / 2,
            'deltaX' => 0,
            'deltaY' => $delta,
        ]);

        return Execution::done();
    }

    private function wait(): Execution
    {
        usleep((int) ($this->waitSeconds * 1_000_000));

        return Execution::done();
    }

    private function mouse(string $type, float $x, float $y, string $button, int $buttons): void
    {
        $this->cdp->send('Input.dispatchMouseEvent', [
            'type' => $type, 'x' => $x, 'y' => $y, 'button' => $button, 'buttons' => $buttons, 'clickCount' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function key(array $key): void
    {
        $this->cdp->send('Input.dispatchKeyEvent', ['type' => 'rawKeyDown', ...$key]);
        $this->cdp->send('Input.dispatchKeyEvent', ['type' => 'keyUp', ...$key]);
    }
}
