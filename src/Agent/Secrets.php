<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;

/**
 * Values the caller lets a run type into secret fields - passwords and card fields - by name.
 * The executor types them; no model is sent one, and a step records that a secret was typed, not
 * which.
 */
final readonly class Secrets
{
    public const string MASK = '<secret>';

    /**
     * @param  array<string, string>  $values  name => value; a field takes the one its label names
     */
    public function __construct(private array $values = []) {}

    /**
     * The secret for the field labelled $label: the one whose name the label contains, or the
     * only one there is.
     */
    public function for(string $label): ?string
    {
        $named = array_filter($this->values, fn (string $value, string $name) => mb_stripos($label, $name) !== false, ARRAY_FILTER_USE_BOTH);

        return match (true) {
            $named !== [] => array_values($named)[0],
            count($this->values) === 1 => array_values($this->values)[0],
            default => null,
        };
    }

    /**
     * The page with a secret field listed but not offered where no secret is given for it, so the
     * text model is never asked to make one up.
     */
    public function offered(Observation $page): Observation
    {
        return $page->withActions(array_map(
            fn (Action $a) => $a->secret && $a->kind === 'fill' && $this->for($a->label) === null ? $a->unavailable() : $a,
            $page->actions,
        ));
    }

    /**
     * The page as a model may see it: every secret's value replaced, so a field shown in clear -
     * after a "Show password" toggle, say - never sends one to a model.
     */
    public function scrub(Observation $page): Observation
    {
        $values = array_values(array_filter($this->values, fn (string $v) => $v !== ''));
        if ($values === []) {
            return $page;
        }
        $scrub = fn (?string $text) => $text === null ? null : str_replace($values, self::MASK, $text);
        $actions = array_map(fn (Action $a) => new Action(...[
            ...get_object_vars($a),
            'label' => (string) $scrub($a->label),
            'value' => $scrub($a->value),
            'currentValue' => $scrub($a->currentValue),
            'context' => $scrub($a->context),
        ]), $page->actions);

        return $page->withContent((string) $scrub($page->title), (string) $scrub($page->text), $actions);
    }
}
