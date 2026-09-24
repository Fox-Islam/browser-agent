<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Executor;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Checks a value for a value input against the format its fill action names and against the
 * input's min, max and step, the way the HTML value sanitisation and constraint rules read them.
 * The browser clears a malformed value and clamps an out-of-range one without telling the page, so
 * a value that fails here is never set.
 */
final class ValueFormat
{
    /**
     * Each format's value as a number on the scale its step counts in (days, seconds, months,
     * weeks or plain numbers), and the step and default range HTML gives it.
     */
    private const array FORMATS = [
        'YYYY-MM-DD' => ['parse' => 'days', 'step' => 1],
        'HH:MM' => ['parse' => 'seconds', 'step' => 60],
        'HH:MM:SS' => ['parse' => 'seconds', 'step' => 60],
        'YYYY-MM-DDTHH:MM' => ['parse' => 'dateTimeSeconds', 'step' => 60],
        'YYYY-MM' => ['parse' => 'months', 'step' => 1],
        'YYYY-Www' => ['parse' => 'weeks', 'step' => 1],
        'number' => ['parse' => 'number', 'step' => 1, 'min' => '0', 'max' => '100'],
    ];

    /** Step arithmetic on floats; a value within this fraction of a step counts as on it. */
    private const float STEP_TOLERANCE = 1e-9;

    /**
     * Why the value cannot be set, or null when it can.
     */
    public static function check(string $format, string $value, ?string $min = null, ?string $max = null, ?string $step = null): ?string
    {
        if ($format === '#rrggbb') {
            return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? null : "{$value} is not a #rrggbb colour";
        }
        $rule = self::FORMATS[$format] ?? null;
        $number = $rule === null ? null : self::parse($rule['parse'], $value);
        if ($number === null) {
            return $rule === null ? "{$format} is not a value format" : "{$value} is not a {$format} value";
        }

        return self::rangeError($rule, $value, $number, $min ?? $rule['min'] ?? null, $max ?? $rule['max'] ?? null)
            ?? self::stepError($rule, $value, $number, $min ?? $rule['min'] ?? null, $step);
    }

    /**
     * @param  array{parse: string, step: int}  $rule
     */
    private static function rangeError(array $rule, string $value, float $number, ?string $min, ?string $max): ?string
    {
        $low = $min === null ? null : self::parse($rule['parse'], $min);
        $high = $max === null ? null : self::parse($rule['parse'], $max);
        if ($low !== null && $number < $low) {
            return "{$value} is below the minimum {$min}";
        }

        return $high !== null && $number > $high ? "{$value} is above the maximum {$max}" : null;
    }

    /**
     * @param  array{parse: string, step: int}  $rule
     */
    private static function stepError(array $rule, string $value, float $number, ?string $min, ?string $step): ?string
    {
        if ($step !== null && mb_strtolower(trim($step)) === 'any') {
            return null;
        }
        $size = $step !== null && is_numeric($step) && (float) $step > 0 ? (float) $step : $rule['step'];
        $base = ($min === null ? null : self::parse($rule['parse'], $min)) ?? 0.0;
        $steps = ($number - $base) / $size;

        return abs($steps - round($steps)) <= self::STEP_TOLERANCE * max(1.0, abs($steps))
            ? null
            : "{$value} is not a whole number of steps of {$size} from " . ($min ?? 'the default base');
    }

    private static function parse(string $kind, string $value): ?float
    {
        return match ($kind) {
            'days' => self::days($value),
            'seconds' => self::seconds($value),
            'dateTimeSeconds' => self::dateTimeSeconds($value),
            'months' => self::months($value),
            'weeks' => self::weeks($value),
            default => self::number($value),
        };
    }

    private static function days(string $value): ?float
    {
        $timestamp = self::timestamp('!Y-m-d', $value, '/^\d{4}-\d{2}-\d{2}$/');

        return $timestamp === null ? null : $timestamp / 86400;
    }

    private static function seconds(string $value): ?float
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m) !== 1) {
            return null;
        }

        return (float) ((int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ($m[3] ?? 0));
    }

    private static function dateTimeSeconds(string $value): ?float
    {
        $parts = explode('T', $value, 2);
        $days = count($parts) === 2 ? self::days($parts[0]) : null;
        $seconds = count($parts) === 2 ? self::seconds($parts[1]) : null;

        return $days === null || $seconds === null ? null : $days * 86400 + $seconds;
    }

    private static function months(string $value): ?float
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m) !== 1) {
            return null;
        }

        return (float) (((int) $m[1] - 1970) * 12 + (int) $m[2] - 1);
    }

    /**
     * An ISO week: a year has week 53 when 28 December falls in it.
     */
    private static function weeks(string $value): ?float
    {
        if (preg_match('/^(\d{4})-W(\d{2})$/', $value, $m) !== 1) {
            return null;
        }
        $year = (int) $m[1];
        $week = (int) $m[2];
        $utc = new DateTimeZone('UTC');
        $weeksInYear = (int) (new DateTimeImmutable("{$year}-12-28", $utc))->format('W');
        $monday = (new DateTimeImmutable('now', $utc))->setISODate($year, $week)->setTime(0, 0);

        return $week >= 1 && $week <= $weeksInYear ? ($monday->getTimestamp() + 3 * 86400) / 604800 : null;
    }

    private static function number(string $value): ?float
    {
        return preg_match('/^-?(\d+(\.\d+)?|\.\d+)([eE][+-]?\d+)?$/', $value) === 1 ? (float) $value : null;
    }

    private static function timestamp(string $format, string $value, string $pattern): ?int
    {
        $date = preg_match($pattern, $value) === 1
            ? DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'))
            : false;

        return $date !== false && $date->format(mb_substr($format, 1)) === $value ? $date->getTimestamp() : null;
    }
}
