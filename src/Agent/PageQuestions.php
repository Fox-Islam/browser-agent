<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Agent;

use JsonException;
use Phox\BrowserAgent\Reader\PageReader;

/**
 * The caller's own JavaScript, asked of the page once the goals named before it are satisfied.
 * It runs outside the decision loop and is not limited by read-only, which governs what the agent
 * does: a query is the caller's code, and running it is the point. Queries run in the reader's
 * world: `el(node)` reaches an element from the latest observation, and page globals are not
 * visible.
 */
final class PageQuestions
{
    /** A query reads a value from the page, not the page itself. */
    public const int ANSWER_LENGTH = 2048;

    private const float TIMEOUT = 30.0;

    /** @var array<int, array{value?: mixed, exception?: string}> */
    private array $answered = [];

    /**
     * @param  list<array{query: string, after: int}>  $asked
     */
    public function __construct(
        private readonly PageReader $reader,
        private readonly array $asked,
    ) {}

    /**
     * Asks every question whose goals are satisfied, once each, and returns all answers so far in
     * the order the questions were given; null for those not yet asked.
     *
     * @return list<array{value?: mixed, exception?: string}|null>
     */
    public function due(int $satisfied): array
    {
        foreach ($this->asked as $index => $question) {
            if (! isset($this->answered[$index]) && $question['after'] <= $satisfied) {
                $this->answered[$index] = $this->ask($question['query']);
            }
        }

        return array_map(fn (int $i) => $this->answered[$i] ?? null, array_keys($this->asked));
    }

    private static function capped(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_substr($value, 0, self::ANSWER_LENGTH);
        }
        try {
            $rendered = json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return mb_strlen($rendered) <= self::ANSWER_LENGTH ? $value : mb_substr($rendered, 0, self::ANSWER_LENGTH);
    }

    /**
     * A promise is awaited. A question that fails is an answer about the page, so it is reported
     * and the run carries on.
     *
     * @return array{value?: mixed, exception?: string}
     */
    private function ask(string $expression): array
    {
        $answer = $this->reader->query($expression, self::TIMEOUT);

        return isset($answer['exception'])
            ? ['exception' => mb_substr($answer['exception'], 0, self::ANSWER_LENGTH)]
            : ['value' => self::capped($answer['value'])];
    }
}
