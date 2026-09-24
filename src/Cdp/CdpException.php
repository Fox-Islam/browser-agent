<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Cdp;

use RuntimeException;

class CdpException extends RuntimeException
{
    /**
     * Chrome answers this way when the document the execution context belonged to is gone, for
     * example after a navigation.
     */
    public function isLostContext(): bool
    {
        return str_contains($this->getMessage(), 'Cannot find context with specified id')
            || str_contains($this->getMessage(), 'Execution context was destroyed');
    }
}
