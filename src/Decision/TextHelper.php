<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use JsonException;
use Phox\BrowserAgent\Agent\Step;
use Phox\BrowserAgent\Executor\ValueFormat;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;

/**
 * Field values from a small OpenAI-compatible model. The executor never guesses or extracts text
 * from the goal itself. A value input's value must match the format its action names, so a value
 * that would be cleared or clamped by the page counts as no value.
 */
final readonly class TextHelper
{
    public const int PAGE_TEXT = 6000;

    public const int VALUE_LENGTH = 2000;

    private const int RECENT_STEPS = 6;

    private const int REJECTED_SHOWN = 120;

    public function __construct(
        private ModelClient $client,
        private ModelConfig $config,
    ) {}

    /**
     * The value for one field.
     *
     * @param  list<Step>  $history
     * @return array{string, array{model: string, latency_ms: int, usage: array<string, mixed>}}
     *
     * @throws UnusableValue
     */
    public function value(string $goal, Action $field, Observation $page, array $history): array
    {
        $context = ['goal' => $goal, 'field' => self::brief($field)] + self::context($page, $history);
        [$content, $spent] = $this->ask(Prompts::TEXT_VALUE, $context);
        $output = self::object($content);
        $value = is_array($output) && array_keys($output) === ['text'] ? self::usable($output['text'], $field) : null;
        if ($value === null) {
            // The rejected reply goes into the message, so the failure can be diagnosed.
            throw new UnusableValue('Text helper returned no valid field value; nothing typed. Got: ' . mb_substr((string) $content, 0, self::REJECTED_SHOWN));
        }

        return [$value, $spent];
    }

    /**
     * Every field value a step needs, in one call. Asked one at a time they are serial at about
     * 600ms each; they do not depend on each other, so together they cost one call. A value that
     * does not hold up is left out, and that field gets its own call.
     *
     * @param  array<string, array{string, Action}>  $fields  field id => sub-goal and field
     * @param  list<Step>  $history
     * @return array{array<string, string>, array<string, mixed>|null}
     */
    public function values(array $fields, Observation $page, array $history): array
    {
        $payload = ['fields' => []] + self::context($page, $history);
        foreach ($fields as $id => [$goal, $field]) {
            $payload['fields'][$id] = ['goal' => $goal, 'field' => self::brief($field)];
        }
        [$content, $spent] = $this->ask(Prompts::TEXT_VALUES, $payload);
        $values = [];
        foreach (self::object($content) ?? [] as $id => $value) {
            $usable = isset($fields[$id]) ? self::usable($value, $fields[$id][1]) : null;
            if ($usable !== null) {
                $values[$id] = $usable;
            }
        }

        return [$values, $values === [] ? null : $spent + ['fields' => count($values)]];
    }

    /**
     * What the helper is told about a field. A value input's format and limits travel with it,
     * so the value comes back in the form the page accepts.
     *
     * @return array<string, string|null>
     */
    private static function brief(Action $field): array
    {
        $brief = ['label' => $field->label, 'role' => $field->role, 'value' => $field->value];
        foreach (['format', 'min', 'max', 'step'] as $hint) {
            if ($field->{$hint} !== null) {
                $brief[$hint] = $field->{$hint};
            }
        }

        return $brief;
    }

    /**
     * @param  list<Step>  $history
     * @return array{page: array{title: string, text: string}, recent_actions: list<array{action: string, text: string|null}>}
     */
    private static function context(Observation $page, array $history): array
    {
        return [
            'page' => ['title' => $page->title, 'text' => mb_substr($page->text, 0, self::PAGE_TEXT)],
            'recent_actions' => array_map(
                fn (Step $s) => ['action' => $s->label, 'text' => $s->text],
                array_values(array_slice($history, -self::RECENT_STEPS)),
            ),
        ];
    }

    private static function usable(mixed $value, Action $field): ?string
    {
        $fits = is_string($value) && trim($value) !== '' && mb_strlen($value) <= self::VALUE_LENGTH;
        $formatted = $fits && ($field->format === null || ValueFormat::check($field->format, $value, $field->min, $field->max, $field->step) === null);

        return $formatted ? $value : null;
    }

    /**
     * The JSON object in a reply. Asking for one does not guarantee it arrives alone: a model may
     * fence it, introduce it or follow it with a remark. Recovering it does not widen what counts
     * as valid, because each value inside is checked either way.
     *
     * @return array<string, mixed>|null
     */
    private static function object(?string $content): ?array
    {
        $text = trim((string) $content);
        $start = mb_strpos($text, '{');
        $end = mb_strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = mb_substr($text, $start, $end - $start + 1);
        }
        try {
            $decoded = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{string|null, array{model: string, latency_ms: int, usage: array<string, mixed>}}
     */
    private function ask(string $instructions, array $payload): array
    {
        if ($this->config->textKey === null || $this->config->textKey === '') {
            throw new ModelException('TYPE_TEXT needs a text model key; no text is hardcoded or guessed by the executor.');
        }
        $started = hrtime(true);
        $result = $this->client->post($this->config->textUrl(), $this->config->textKey, [
            'model' => $this->config->textModel,
            'max_tokens' => 4096,
            'response_format' => ['type' => 'json_object'],
            ...$this->config->reasoning(),
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ],
        ]);
        $content = $result['choices'][0]['message']['content'] ?? null;

        return [is_string($content) ? $content : null, [
            'model' => $this->config->textModel,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'usage' => is_array($result['usage'] ?? null) ? $result['usage'] : [],
        ]];
    }
}
