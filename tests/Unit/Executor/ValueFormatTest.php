<?php

declare(strict_types=1);

use Phox\BrowserAgent\Executor\ValueFormat;

it('accepts values in the named format', function (string $format, string $value, ?string $step = null): void {
    expect(ValueFormat::check($format, $value, step: $step))->toBeNull();
})->with([
    'date' => ['YYYY-MM-DD', '2026-09-24'],
    'leap day' => ['YYYY-MM-DD', '2028-02-29'],
    'time' => ['HH:MM', '14:30'],
    'time with seconds' => ['HH:MM:SS', '14:30:05', '1'],
    'datetime-local' => ['YYYY-MM-DDTHH:MM', '2026-09-24T14:30'],
    'month' => ['YYYY-MM', '2026-09'],
    'week' => ['YYYY-Www', '2026-W39'],
    'week 53' => ['YYYY-Www', '2026-W53'],
    'colour' => ['#rrggbb', '#336699'],
    'upper-case colour' => ['#rrggbb', '#AABBCC'],
    'range' => ['number', '40'],
    'fractional range' => ['number', '2.5', '0.5'],
]);

it('rejects values that are not in the named format', function (string $format, string $value, string $reason): void {
    expect(ValueFormat::check($format, $value))->toBe($reason);
})->with([
    'locale date' => ['YYYY-MM-DD', '24/09/2026', '24/09/2026 is not a YYYY-MM-DD value'],
    'impossible date' => ['YYYY-MM-DD', '2026-02-30', '2026-02-30 is not a YYYY-MM-DD value'],
    'twelve-hour time' => ['HH:MM', '2:30 PM', '2:30 PM is not a HH:MM value'],
    'hour 24' => ['HH:MM', '24:00', '24:00 is not a HH:MM value'],
    'datetime with a space' => ['YYYY-MM-DDTHH:MM', '2026-09-24 14:30', '2026-09-24 14:30 is not a YYYY-MM-DDTHH:MM value'],
    'month 13' => ['YYYY-MM', '2026-13', '2026-13 is not a YYYY-MM value'],
    'week 53 in a 52-week year' => ['YYYY-Www', '2025-W53', '2025-W53 is not a YYYY-Www value'],
    'colour name' => ['#rrggbb', 'red', 'red is not a #rrggbb colour'],
    'short colour' => ['#rrggbb', '#abc', '#abc is not a #rrggbb colour'],
    'range word' => ['number', 'forty', 'forty is not a number value'],
    'unknown format' => ['DD/MM', '24/09', 'DD/MM is not a value format'],
]);

it('rejects values outside min and max', function (string $format, string $value, ?string $min, ?string $max, string $reason): void {
    expect(ValueFormat::check($format, $value, $min, $max))->toBe($reason);
})->with([
    'date before min' => ['YYYY-MM-DD', '2025-12-31', '2026-01-01', null, '2025-12-31 is below the minimum 2026-01-01'],
    'date after max' => ['YYYY-MM-DD', '2027-01-01', null, '2026-12-31', '2027-01-01 is above the maximum 2026-12-31'],
    'time after max' => ['HH:MM', '18:00', '09:00', '17:00', '18:00 is above the maximum 17:00'],
    'week before min' => ['YYYY-Www', '2026-W01', '2026-W10', null, '2026-W01 is below the minimum 2026-W10'],
    'range above its default max' => ['number', '101', null, null, '101 is above the maximum 100'],
    'range below its default min' => ['number', '-1', null, null, '-1 is below the minimum 0'],
]);

it('accepts values on the edges of min and max', function (): void {
    expect(ValueFormat::check('YYYY-MM-DD', '2026-01-01', '2026-01-01', '2026-12-31'))->toBeNull()
        ->and(ValueFormat::check('number', '100'))->toBeNull();
});

it('rejects values off the step, counted from min', function (string $format, string $value, ?string $min, ?string $step, string $reason): void {
    expect(ValueFormat::check($format, $value, $min, null, $step))->toBe($reason);
})->with([
    'range off step 5' => ['number', '42', '0', '5', '42 is not a whole number of steps of 5 from 0'],
    'range off step from min' => ['number', '10', '1', '2', '10 is not a whole number of steps of 2 from 1'],
    'seconds on a minute input' => ['HH:MM', '14:30:05', null, null, '14:30:05 is not a whole number of steps of 60 from the default base'],
    'weekly date' => ['YYYY-MM-DD', '2026-09-25', '2026-09-24', '7', '2026-09-25 is not a whole number of steps of 7 from 2026-09-24'],
    'quarter-hour time' => ['HH:MM', '14:20', null, '900', '14:20 is not a whole number of steps of 900 from the default base'],
]);

it('accepts values on the step, and any value with step any', function (): void {
    expect(ValueFormat::check('number', '45', '0', '50', '5'))->toBeNull()
        ->and(ValueFormat::check('YYYY-MM-DD', '2026-10-01', '2026-09-24', null, '7'))->toBeNull()
        ->and(ValueFormat::check('HH:MM', '14:15', null, null, '900'))->toBeNull()
        ->and(ValueFormat::check('number', '0.3', '0', '1', '0.1'))->toBeNull()
        ->and(ValueFormat::check('number', '42.123', null, null, 'any'))->toBeNull();
});

it('falls back to the default step when step is not a positive number', function (): void {
    expect(ValueFormat::check('number', '2.5', null, null, '0'))->toBe('2.5 is not a whole number of steps of 1 from 0');
});
