<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Executor;

use InvalidArgumentException;
use Phox\BrowserAgent\Cdp\CdpSession;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReadingMode;

/**
 * Carries out one action from an observation. Before a click, fill or select it confirms the page
 * key, the control's guard and the control at the click point are as observed; any difference is a
 * stale page and nothing is done. No step is ever retried: a repeated click or keystroke can
 * resubmit a form on a live site.
 */
final readonly class Executor
{
    private const int CTRL = 2;

    public function __construct(
        private CdpSession $cdp,
        private PageReader $reader,
        private float $waitSeconds = 1.0,
    ) {}

    /**
     * @param  string|null  $text  what to fill; required for fill actions
     */
    public function execute(Observation $observation, Action $action, ?string $text = null): Execution
    {
        if ($observation->mode === ReadingMode::Document) {
            throw new InvalidArgumentException('A document-mode observation is for reading, not acting on');
        }

        return match ($action->kind) {
            'scroll' => $this->scroll($observation, (int) $action->delta),
            'wait' => $this->wait(),
            'click', 'fill', 'select' => $this->operate($observation, $action, $text),
            default => throw new InvalidArgumentException("Unknown action kind {$action->kind}"),
        };
    }

    private function operate(Observation $observation, Action $action, ?string $text): Execution
    {
        if (! $action->available) {
            throw new InvalidArgumentException("Action {$action->id} is not available to this run");
        }
        if ($action->kind === 'fill' && $text === null) {
            throw new InvalidArgumentException("Fill action {$action->id} needs a value");
        }
        $refusal = $action->kind === 'fill' && $action->format !== null
            ? ValueFormat::check($action->format, (string) $text, $action->min, $action->max, $action->step)
            : null;
        $stale = $refusal === null ? $this->staleReason($observation, $action) : null;

        return match (true) {
            $refusal !== null => Execution::rejected($refusal),
            $stale !== null => Execution::stale($stale),
            default => $this->perform($action, (string) $text),
        };
    }

    private function perform(Action $action, string $text): Execution
    {
        $altered = match (true) {
            $action->kind === 'click' => $this->click($action),
            $action->kind === 'fill' && $action->format === null => $this->type($action, $text),
            $action->kind === 'select' => $this->set($action, (string) $action->value),
            default => $this->set($action, $text),
        };

        return $altered === null ? Execution::done() : Execution::altered($altered);
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

    private function staleReason(Observation $observation, Action $action): ?string
    {
        $node = (int) $action->node;
        if ($this->reader->pageKey() !== $observation->pageKey) {
            return 'the page changed';
        }
        if ($this->reader->guard($node) !== $observation->guard($node)) {
            return 'the control changed';
        }
        [$x, $y] = $this->centre($action);
        $blocker = $this->reader->blocker($node, $x, $y);

        return $blocker === null ? null : "in the way: {$blocker}";
    }

    private function click(Action $action): null
    {
        [$x, $y] = $this->centre($action);
        $this->mouse('mouseMoved', $x, $y, button: 'none', buttons: 0);
        $this->mouse('mousePressed', $x, $y, button: 'left', buttons: 1);
        $this->mouse('mouseReleased', $x, $y, button: 'left', buttons: 0);

        return null;
    }

    /**
     * Select-all goes through Chrome's editing command instead of a platform shortcut, so it works
     * whatever OS the browser reports.
     */
    private function type(Action $action, string $text): null
    {
        $this->click($action);
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

    /**
     * @return array{float, float}
     */
    private function centre(Action $action): array
    {
        $rect = $action->rect;

        return [$rect->x + $rect->w / 2, $rect->y + $rect->h / 2];
    }
}
