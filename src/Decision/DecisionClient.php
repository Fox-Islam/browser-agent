<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use GuzzleHttp\ClientInterface;
use Phox\TypeSafe\Assets\Image;
use Phox\TypeSafe\Client;
use Phox\TypeSafe\Enums\LogLevel;
use Phox\TypeSafe\Exceptions\ApiException;
use Phox\TypeSafe\Exceptions\ConnectionException;
use Phox\TypeSafe\Exceptions\TypeSafeException;
use Phox\TypeSafe\Questions\Choice;
use Phox\TypeSafe\Questions\Noul;
use Phox\TypeSafe\Questions\Question;
use Phox\TypeSafe\Retry\RetryPolicy;
use Phox\TypeSafe\TypeSafe;

/**
 * Decisions through the TypeSafe SDK, over the run's one HTTP client. A request with an image
 * carries the images opt-in header; TypeSafe takes the image as a multipart part and OpenRouter as
 * an inline `image_url` data URI. A model call changes nothing on the page, so an overloaded
 * provider is asked again.
 */
final readonly class DecisionClient
{
    private Client $client;

    public function __construct(ClientInterface $http, private ModelConfig $config, float $backoff = 0.5)
    {
        $this->client = Client::make(
            apiKey: $config->typesafeKey,
            baseUrl: $config->typesafeBaseUrl,
            defaultModel: $config->typesafeModel,
            provider: $config->provider(),
        )
            ->httpClient($http)
            ->timeout($config->timeout)
            ->logLevel(LogLevel::Off)
            ->retry(RetryPolicy::default()->retryStatuses(429, 503, 529)->initialBackoff($backoff)->jitter(0.0)
                ->retryConnectionErrors(false)->retryTimeouts(false));
    }

    /**
     * @param  array<string, mixed>  $state  an {@see Image} in it is uploaded with the request
     * @param  array<string, array{type: string, instructions: string, criteria?: object|array<string, string>}>  $questions
     * @return array{answers: array<string, mixed>, model: string, usage: array<string, mixed>}
     *
     * @throws ModelException
     */
    public function ask(array $state, array $questions, bool $images = false): array
    {
        $request = $this->client->systemOne()->state($state)->questions(array_map(self::question(...), $questions));
        if ($images) {
            $request->header(TypeSafe::IMAGES_HEADER, 'true');
        }
        try {
            $data = $request->send()->toArray();
        } catch (ApiException $e) {
            throw new ModelException("Model provider returned HTTP {$e->getStatus()}; no action executed.", previous: $e);
        } catch (ConnectionException $e) {
            throw new ModelException('Model connection failed; no action executed.', previous: $e);
        } catch (TypeSafeException $e) {
            throw new ModelException('Invalid TypeSafe response; no action executed.', previous: $e);
        }

        return [
            'answers' => is_array($data['answers'] ?? null) ? $data['answers'] : [],
            'model' => is_string($data['model'] ?? null) ? $data['model'] : '',
            'usage' => is_array($data['usage'] ?? null) ? $data['usage'] : [],
        ];
    }

    /**
     * @param  array{type: string, instructions: string, criteria?: object|array<string, string>}  $question
     */
    private static function question(array $question): Question
    {
        $criteria = (array) ($question['criteria'] ?? []);
        if ($question['type'] === 'noul') {
            $noul = Noul::ask($question['instructions']);

            return $criteria === [] ? $noul : $noul->yes($criteria['true'] ?? null)->no($criteria['false'] ?? null);
        }
        $choice = Choice::ask($question['instructions']);
        foreach ($criteria as $label => $description) {
            $choice->option((string) $label, $description);
        }

        return $choice;
    }
}
