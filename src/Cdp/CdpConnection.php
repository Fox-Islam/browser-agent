<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Cdp;

use Closure;

/**
 * A browser-level CDP connection. Page sessions share it through flattened session ids.
 */
interface CdpConnection
{
    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws CdpTimeoutException when no answer arrives within the timeout
     * @throws CdpException on a protocol error or a closed connection
     */
    public function call(string $method, array $params = [], ?string $sessionId = null, ?float $timeout = null): array;

    /**
     * Calls the listener with the method and params of every event sent for a session.
     *
     * @param  Closure(string, array<string, mixed>): void  $listener
     */
    public function listen(string $sessionId, Closure $listener): void;

    public function close(): void;
}
