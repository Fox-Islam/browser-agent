<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use Phox\BrowserAgent\Reader\Action;

/**
 * TypeSafe question bodies. The API takes descriptions and instructions as strings, and a server
 * that holds to the spec rejects anything else, so roles, values and rules go into sentences.
 */
final class Questions
{
    /**
     * @param  array<string, string>  $criteria
     * @return array{type: string, criteria: object, instructions: string}
     */
    public static function choice(array $criteria, string $instructions): array
    {
        // An object even when the keys look like list indices, which json_encode would otherwise
        // send as an array.
        return ['type' => 'choice', 'criteria' => (object) $criteria, 'instructions' => $instructions];
    }

    /**
     * One target head per operation with more than one candidate.
     *
     * @param  array<string, array<string, Action>>  $targets
     * @param  array<string, string>  $settled
     * @param  list<string>  $notes  rules added after the standard ones
     * @return array<string, array<string, mixed>>
     */
    public static function targets(string $goal, array $targets, array $settled, string $prefix = '', array $notes = []): array
    {
        $questions = [];
        foreach ($targets as $operation => $candidates) {
            if (isset($settled[$operation])) {
                continue;
            }
            $criteria = [];
            foreach ($candidates as $index => $action) {
                $criteria[(string) $index] = self::describe((string) $index, $action);
            }
            $questions[$prefix . mb_strtolower($operation) . '_target'] = self::choice(
                $criteria,
                self::instructions($goal, [Prompts::NEXT_ACTION, Prompts::TARGET, ...$notes], $operation),
            );
        }

        return $questions;
    }

    /**
     * @param  list<string>  $rules
     */
    public static function instructions(string $goal, array $rules, ?string $operation = null): string
    {
        $head = ["Goal: {$goal}"];
        if ($operation !== null) {
            $head[] = "Operation under consideration: {$operation}";
        }

        return implode("\n\n", [...$head, ...$rules]);
    }

    public static function describe(string $index, Action $action): string
    {
        $parts = ["Element [{$index}], labelled " . self::quote($action->label)];
        if ($action->role !== null && $action->role !== '') {
            $parts[] = "a {$action->role}";
        }
        $value = $action->currentValue ?? $action->value ?? '';
        if ($value !== '') {
            $parts[] = 'currently holding ' . self::quote($value);
        }
        foreach (['checked', 'selected', 'expanded'] as $flag) {
            if ($action->{$flag} !== null) {
                $parts[] = "{$flag}: {$action->{$flag}}";
            }
        }

        return implode(', ', $parts) . '.';
    }

    private static function quote(string $text): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $text) . "'";
    }
}
