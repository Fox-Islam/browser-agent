<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;

/**
 * Keeps a form from being submitted twice with nothing changed. A form's submit controls are not
 * offered again, though they stay visible, until a fill or select on that form has changed a value:
 * on a valid form every repeat is another real submission, and a form that re-renders its response
 * area on each submit makes every repeat look like progress.
 */
final class SubmitGuard
{
    /** @var array<string, true> forms submitted since their last edit, by document and form node */
    private array $submitted = [];

    /**
     * Records what an executed action did to its form.
     */
    public function record(Action $action, Observation $page, ?string $text): void
    {
        if ($action->form === null) {
            return;
        }
        $key = self::key($page->url, $action->form);
        if ($action->kind === 'click' && $action->submits) {
            $this->submitted[$key] = true;
        } elseif ($action->kind === 'select' || ($action->kind === 'fill' && $text !== $action->value)) {
            unset($this->submitted[$key]);
        }
    }

    /**
     * The page with the submit controls of forms submitted since their last edit marked
     * unavailable: listed, never offered.
     */
    public function mark(Observation $page): Observation
    {
        if ($this->submitted === []) {
            return $page;
        }

        return $page->withActions(array_map(
            fn (Action $a) => $a->submits && $a->form !== null && isset($this->submitted[self::key($page->url, $a->form)]) ? $a->unavailable() : $a,
            $page->actions,
        ));
    }

    /**
     * Node handles restart in each document, so a form is known by its page address and node. The
     * fragment is left out: a form that reports its result by changing it is the same form.
     */
    private static function key(string $url, int $form): string
    {
        return explode('#', $url, 2)[0] . "\0" . $form;
    }
}
