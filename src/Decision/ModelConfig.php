<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

/**
 * Where decisions and field values come from. The caller supplies keys; nothing reads the
 * environment.
 */
final readonly class ModelConfig
{
    public const string TYPESAFE_DECISION_PATH = '/v1/systemone';

    public const string OPENROUTER_BASE_URL = 'https://openrouter.ai';

    public const string OPENROUTER_DECISION_PATH = '/api/alpha/decisions';

    public function __construct(
        public string $typesafeKey,
        public string $typesafeBaseUrl = 'https://api.typesafe.ai',
        public string $typesafeModel = 'jev-latest',
        public ?string $textKey = null,
        public string $textBaseUrl = 'https://api.deepseek.com/v1',
        public string $textModel = 'deepseek-chat',
        public bool $textReasoning = true,
        public float $timeout = 25.0,
        public string $decisionPath = self::TYPESAFE_DECISION_PATH,
    ) {}

    /**
     * Any server implementing /v1/systemone answers a decision, so a local one is a base URL.
     * OpenRouter answers the same request body at its own path, keyed by an OpenRouter key.
     */
    public function decisionUrl(): string
    {
        return rtrim($this->typesafeBaseUrl, '/') . '/' . ltrim($this->decisionPath, '/');
    }

    public function textUrl(): string
    {
        return rtrim($this->textBaseUrl, '/') . '/chat/completions';
    }

    /**
     * DeepSeek spells the reasoning switch differently from providers reached through OpenRouter,
     * and some models accept neither, which $textReasoning = false covers.
     *
     * @return array<string, mixed>
     */
    public function reasoning(): array
    {
        return match (true) {
            ! $this->textReasoning => ['reasoning' => ['enabled' => false]],
            str_contains($this->textBaseUrl, 'api.deepseek.com') => ['thinking' => ['type' => 'disabled']],
            default => ['reasoning' => ['effort' => 'low']],
        };
    }
}
