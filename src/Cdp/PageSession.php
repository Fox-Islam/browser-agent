<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Cdp;

use Closure;

/**
 * A page target's flattened session on a shared browser connection.
 */
final readonly class PageSession implements CdpSession
{
    public function __construct(
        private CdpConnection $connection,
        public string $sessionId,
        private ?float $timeout = null,
    ) {}

    public function send(string $method, array $params = [], ?float $timeout = null): array
    {
        return $this->connection->call($method, $params, $this->sessionId, $timeout ?? $this->timeout);
    }

    public function listen(Closure $listener): void
    {
        $this->connection->listen($this->sessionId, $listener);
    }
}
