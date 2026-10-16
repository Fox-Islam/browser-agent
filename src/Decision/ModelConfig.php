<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Decision;

use InvalidArgumentException;
use Phox\TypeSafe\Enums\Provider;
use Phox\TypeSafe\TypeSafe;

/**
 * Where decisions and field values come from. The caller supplies keys; nothing reads the
 * environment.
 */
final readonly class ModelConfig
{
    public const string TYPESAFE_DECISION_PATH = '/v1/systemone';

    public const string OPENROUTER_BASE_URL = 'https://openrouter.ai';

    public const string OPENROUTER_DECISION_PATH = TypeSafe::OPENROUTER_SYSTEM_ONE_PATH;

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
        public ImageMode $images = ImageMode::Off,
    ) {
        if (! in_array('/' . ltrim($decisionPath, '/'), [self::TYPESAFE_DECISION_PATH, self::OPENROUTER_DECISION_PATH], true)) {
            throw new InvalidArgumentException("No decision provider answers at {$decisionPath}.");
        }
        if ($images !== ImageMode::Off && ! extension_loaded('gd')) {
            throw new InvalidArgumentException('Images need the gd extension.');
        }
    }

    public function provider(): Provider
    {
        return '/' . ltrim($this->decisionPath, '/') === self::OPENROUTER_DECISION_PATH ? Provider::OpenRouter : Provider::TypeSafe;
    }

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
