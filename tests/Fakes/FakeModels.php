<?php

declare(strict_types=1);

namespace Tests\Fakes;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Phox\BrowserAgent\Decision\ModelConfig;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * TypeSafe and the text helper, answered from a script. A decision names an operation and, for
 * operations with targets, the label of the control; every other head gets a valid answer built
 * from the question's own criteria, as the real service would give.
 */
final class FakeModels
{
    /** @var list<array{url: string, body: array<string, mixed>}> */
    public array $requests = [];

    public Client $http;

    /** Seconds each decision takes to arrive, so a page can change while it is on its way. */
    public float $latency = 0.0;

    /**
     * Each decision is an operation, the label of its target, satisfied readings per sub-goal
     * (`plan`), what each sub-goal would do (`holds`: operation and target label), and an
     * operation to answer while the first is not offered (`else`). `plan` and `holds` are keyed by
     * position among the outstanding sub-goals, as the questions are.
     *
     * @param  list<array{0: string, 1?: string, plan?: array<int, float>, holds?: array<int, array{0: string, 1?: string}>, else?: string}>  $decisions
     * @param  array<string, string|null>  $values  field label => value the text helper gives
     */
    public function __construct(private array $decisions = [], private array $values = [])
    {
        $this->http = new Client(['handler' => HandlerStack::create(
            fn (RequestInterface $request, array $options) => Create::promiseFor($this->answer($request)),
        )]);
    }

    public static function config(): ModelConfig
    {
        return new ModelConfig(typesafeKey: 'ts-key', typesafeBaseUrl: 'https://typesafe.test', textKey: 'text-key', textBaseUrl: 'https://text.test/v1');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function decisionRequests(): array
    {
        return array_values(array_map(fn ($r) => $r['body'], array_filter($this->requests, fn ($r) => str_ends_with($r['url'], '/v1/systemone'))));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function textRequests(): array
    {
        return array_values(array_map(fn ($r) => $r['body'], array_filter($this->requests, fn ($r) => str_ends_with($r['url'], '/chat/completions'))));
    }

    /**
     * @param  array<string, string>  $criteria
     */
    private static function labelled(array $criteria, ?string $label): string
    {
        foreach ($criteria as $index => $description) {
            if (str_contains($description, "labelled '{$label}'")) {
                return (string) $index;
            }
        }

        throw new RuntimeException("No target labelled {$label}");
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{choice: string, probabilities: array<string, float>, confidence: float}
     */
    private static function choice(array $ids, string $choice): array
    {
        $ids = array_map('strval', $ids);
        $rest = count($ids) > 1 ? 0.1 / (count($ids) - 1) : 0.0;
        $probabilities = array_combine($ids, array_map(fn (string $id) => $id === $choice ? (count($ids) > 1 ? 0.9 : 1.0) : $rest, $ids));

        return ['choice' => $choice, 'probabilities' => $probabilities, 'confidence' => 0.9];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private static function json(array $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function answer(RequestInterface $request): Response
    {
        usleep((int) ($this->latency * 1_000_000));
        $body = json_decode((string) $request->getBody(), true);
        $this->requests[] = ['url' => (string) $request->getUri(), 'body' => $body];

        return str_ends_with((string) $request->getUri(), '/chat/completions')
            ? self::json(['choices' => [['message' => ['content' => $this->text($body)]]], 'usage' => ['total_tokens' => 10]])
            : self::json(['answers' => $this->decide($body['questions']), 'model' => 'jev-test', 'usage' => ['total_tokens' => 20]]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    private function decide(array $questions): array
    {
        $scripted = array_shift($this->decisions) ?? throw new RuntimeException('The fake ran out of decisions');
        if (isset($scripted['else']) && ! isset($questions['operation']['criteria'][$scripted[0]])) {
            // Answered with the fallback until the scripted operation is offered.
            array_unshift($this->decisions, $scripted);
            $scripted = [$scripted['else']];
        }
        if (isset($scripted['unless']) && ! str_contains($questions['operation']['instructions'], $scripted['unless'])) {
            // Stands in for a model that answers otherwise without the instruction it needs.
            $scripted = [$scripted['otherwise']];
        }
        [$operation, $label] = [$scripted[0], $scripted[1] ?? null];
        $answers = [];
        foreach ($questions as $name => $question) {
            $hold = preg_match('/^plan(\d+)_(operation|(\w+)_target)$/', $name, $m) === 1 ? ($scripted['holds'][(int) $m[1]] ?? null) : null;
            if ($hold !== null) {
                $answers[$name] = $m[2] === 'operation'
                    ? self::choice(array_keys($question['criteria']), $hold[0])
                    : self::choice(array_keys($question['criteria']), $m[3] === mb_strtolower($hold[0]) ? self::labelled($question['criteria'], $hold[1] ?? null) : (string) array_key_first($question['criteria']));

                continue;
            }
            $answers[$name] = match (true) {
                $question['type'] === 'noul' => ['noul' => $scripted['plan'][(int) mb_substr($name, 4)] ?? 0.1],
                $name === 'operation' => self::choice(array_keys($question['criteria']), $operation),
                // A sub-goal with nothing scripted holds WAIT, which is never acted on later.
                str_ends_with($name, '_operation') && isset($question['criteria']['WAIT']) => self::choice(array_keys($question['criteria']), 'WAIT'),
                $name === mb_strtolower($operation) . '_target' => self::choice(array_keys($question['criteria']), self::labelled($question['criteria'], $label)),
                default => self::choice(array_keys($question['criteria']), (string) array_key_first($question['criteria'])),
            };
        }

        return $answers;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function text(array $body): string
    {
        $payload = json_decode($body['messages'][1]['content'], true);
        if (isset($payload['fields'])) {
            return json_encode(array_map(fn ($f) => $this->values[$f['field']['label']] ?? null, $payload['fields']));
        }

        return json_encode(['text' => $this->values[$payload['field']['label']] ?? null]);
    }
}
