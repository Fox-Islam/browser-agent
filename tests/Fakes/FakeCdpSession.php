<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Closure;
use Phox\BrowserAgent\Cdp\CdpSession;

/**
 * Answers CDP calls from per-method handlers and records every call.
 */
final class FakeCdpSession implements CdpSession
{
    /** @var list<array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<Closure(string, array<string, mixed>): void> */
    public array $listeners = [];

    /**
     * @param  array<string, Closure(array<string, mixed>): array<string, mixed>>  $handlers
     */
    public function __construct(private array $handlers = []) {}

    public function on(string $method, Closure $handler): self
    {
        $this->handlers[$method] = $handler;

        return $this;
    }

    public function send(string $method, array $params = [], ?float $timeout = null): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return isset($this->handlers[$method]) ? ($this->handlers[$method])($params) : [];
    }

    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function emit(string $method, array $params = []): void
    {
        foreach ($this->listeners as $listener) {
            $listener($method, $params);
        }
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }
}
