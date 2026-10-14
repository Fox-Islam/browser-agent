<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Reader\Action;

/**
 * Where a decision is sent a screenshot: where the page's text cannot tell controls apart or show
 * a state, as with unnamed icons, unlabelled colour swatches, a tab highlighted only by colour or
 * a failure drawn only by CSS. Elsewhere a screenshot adds cost and changes nothing.
 */
final class Looking
{
    /** Goals that name what something looks like or where it sits, which a page may show only by styling. */
    public const string VISUAL = '/\b(colou?r\w*|red|green|blue|yellow|orange|purple|pink|black|white|gr[ae]y|highlight\w*|icons?|images?|pictures?|photos?|logos?|layout|overlap\w*|broken|styl(e|ed|ing)|(left|right) of|next to)\b/i';

    /** Below this, an operation other than DONE marks a page whose answer the text does not show. */
    public const float UNCERTAIN = 0.6;

    /** Below this, a false DONE and a right one cannot be told apart by confidence. */
    public const float DONE_SURE = 0.9;

    /**
     * Whether the decision is sent a screenshot from the start. $look is true for a decision
     * being asked again; see {@see secondLook()}.
     *
     * @param  list<Action>  $actions
     */
    public static function wanted(ImageMode $mode, bool $look, array $actions, string $goal): bool
    {
        return match ($mode) {
            ImageMode::Off => false,
            ImageMode::Always => true,
            ImageMode::Auto => $look || self::unnamedRow($actions) || preg_match(self::VISUAL, $goal) === 1,
        };
    }

    /**
     * Whether a text-only answer is asked again with a screenshot: it ends the run, or the model
     * was unsure which operation to take. Target confidence is not a reason: text-only is surest of
     * its target where it cannot see the difference between candidates.
     */
    public static function secondLook(string $operation, float $confidence): bool
    {
        return $operation === 'BLOCKED'
            || ($operation === 'DONE' && $confidence < self::DONE_SURE)
            || ($operation !== 'DONE' && $confidence < self::UNCERTAIN);
    }

    /**
     * Whether two or more unnamed controls in view sit together, as an icon toolbar or a set of
     * swatches do. An unnamed control alone does not count: real pages often have one, and rarely
     * two together.
     *
     * @param  list<Action>  $actions
     */
    public static function unnamedRow(array $actions): bool
    {
        $groups = [];
        foreach ($actions as $action) {
            if ($action->group !== null && $action->offscreen === null && $action->node !== null) {
                $groups[$action->group][$action->node] = true;
            }
        }

        return array_filter($groups, fn (array $nodes) => count($nodes) >= 2) !== [];
    }
}
