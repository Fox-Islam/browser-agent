<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

/**
 * Checks a TypeSafe choice answer before anything is done with it.
 */
final class Choices
{
    /** Probabilities may round; a distribution this close to 1 counts as one. */
    private const float SUM_TOLERANCE = 0.02;

    /**
     * The answer, when its choice is one of the ids, its probabilities cover exactly those ids and
     * sum to one, and the choice is the most probable.
     *
     * @param  list<string>  $ids
     * @return array{choice: string, probabilities: array<string, float>, confidence: float}
     *
     * @throws ModelException
     */
    public static function validate(mixed $answer, array $ids): array
    {
        $probabilities = is_array($answer) ? ($answer['probabilities'] ?? null) : null;
        $valid = is_array($probabilities)
            && in_array($answer['choice'] ?? null, $ids, true)
            && self::sameIds(array_map('strval', array_keys($probabilities)), $ids)
            && self::isProbability($answer['confidence'] ?? null)
            && array_filter($probabilities, fn ($p) => ! self::isProbability($p)) === []
            && abs(array_sum($probabilities) - 1) < self::SUM_TOLERANCE
            && $probabilities[$answer['choice']] >= max($probabilities) - 1e-6;
        if (! $valid) {
            throw new ModelException('Invalid TypeSafe response; no action executed.');
        }
        $probabilities = array_combine(array_map('strval', array_keys($probabilities)), array_map('floatval', $probabilities));

        return ['choice' => (string) $answer['choice'], 'probabilities' => $probabilities, 'confidence' => (float) $answer['confidence']];
    }

    private static function isProbability(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1;
    }

    /**
     * @param  list<string>  $found
     * @param  list<string>  $ids
     */
    private static function sameIds(array $found, array $ids): bool
    {
        sort($found);
        sort($ids);

        return $found === array_values(array_unique($ids));
    }
}
