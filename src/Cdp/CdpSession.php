<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Cdp;

use Closure;

/**
 * A CDP session attached to one page target.
 */
interface CdpSession
{
    /**
     * @param  array<string, mixed>  $params
     * @param  float|null  $timeout  seconds to wait for the answer; null takes the session's own
     * @return array<string, mixed>
     *
     * @throws CdpException when the browser answers with a protocol error or not in time
     */
    public function send(string $method, array $params = [], ?float $timeout = null): array;

    /**
     * Calls the listener with the method and params of every event the page target sends.
     *
     * @param  Closure(string, array<string, mixed>): void  $listener
     */
    public function listen(Closure $listener): void;
}
