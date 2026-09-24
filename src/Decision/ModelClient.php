<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

/**
 * JSON over one HTTP client for every model call in a run: a fresh TLS connection per request
 * costs ~540ms against ~205ms on a reused one. A model call changes nothing on the page, so an
 * overloaded provider is asked again.
 */
final readonly class ModelClient
{
    private const array RETRIED_STATUSES = [429, 503, 529];

    private const int ATTEMPTS = 3;

    public function __construct(
        private ClientInterface $http,
        private float $timeout = 25.0,
        private float $backoff = 0.5,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $url, string $key, array $body): array
    {
        for ($attempt = 1; ; $attempt++) {
            $response = $this->send($url, $key, $body);
            $status = $response->getStatusCode();
            if (! in_array($status, self::RETRIED_STATUSES, true) || $attempt === self::ATTEMPTS) {
                break;
            }
            usleep((int) ($this->backoff * 2 ** ($attempt - 1) * 1_000_000));
        }
        if ($status >= 400) {
            throw new ModelException("Model provider returned HTTP {$status}; no action executed.");
        }

        return self::decode((string) $response->getBody());
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ModelException('Model provider returned something other than JSON; no action executed.', previous: $e);
        }

        return is_array($decoded) ? $decoded : throw new ModelException('Model provider returned no JSON object; no action executed.');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $url, string $key, array $body): ResponseInterface
    {
        try {
            return $this->http->request('POST', $url, [
                'json' => $body,
                'headers' => ['Authorization' => "Bearer {$key}"],
                'timeout' => $this->timeout,
                'connect_timeout' => min(10.0, $this->timeout),
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new ModelException('Model connection failed; no action executed.', previous: $e);
        }
    }
}
