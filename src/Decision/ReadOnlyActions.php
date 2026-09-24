<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Reader\Action;

/**
 * What a read-only run may do: click to navigate or reveal, never type, select or submit. Other
 * controls stay in the observation, marked unavailable, so the model can tell they exist, but no
 * target head offers them, so they cannot be chosen however the goal is worded. A form submitter
 * is known from the reader; a button that posts through JavaScript is not, so its label is the
 * evidence for it.
 */
final class ReadOnlyActions
{
    /**
     * Words that make a control likely to send something instead of moving around.
     */
    private const array SUBMITS = [
        'submit', 'send', 'search', 'sign in', 'log in', 'register', 'subscribe', 'donate', 'buy',
        'pay', 'checkout', 'order', 'book', 'apply', 'continue', 'next', 'confirm', 'save', 'delete',
        'remove', 'post', 'comment', 'upload',
    ];

    /**
     * @param  list<Action>  $actions
     * @return list<Action>
     */
    public static function mark(array $actions): array
    {
        return array_map(fn (Action $a) => self::writes($a) ? $a->unavailable() : $a, $actions);
    }

    public static function writes(Action $action): bool
    {
        return in_array($action->kind, ['fill', 'select'], true)
            || ($action->kind === 'click' && ($action->submits || self::submits($action->label)));
    }

    public static function submits(string $label): bool
    {
        $label = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $label)));
        foreach (self::SUBMITS as $word) {
            if ($label === $word || str_starts_with($label, "{$word} ") || str_ends_with($label, " {$word}")) {
                return true;
            }
        }

        return false;
    }
}
