<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Cdp;

/**
 * One page in its own browser context, so cookies and storage never cross between runs that
 * share a browser. Closing the tab disposes the context and everything in it.
 */
final class BrowserTab
{
    private bool $closed = false;

    private function __construct(
        private readonly CdpConnection $connection,
        public readonly string $targetId,
        public readonly string $contextId,
        public readonly PageSession $session,
    ) {}

    /**
     * A new page in a new browser context.
     *
     * @param  bool  $keepOpen  keeps the context when this connection goes, so another process
     *                          can attach to the page later; otherwise it is disposed on detach
     */
    public static function open(
        CdpConnection $connection,
        int $width = 1120,
        int $height = 780,
        ?float $timeout = null,
        bool $keepOpen = false,
    ): self {
        $context = $connection->call('Target.createBrowserContext', ['disposeOnDetach' => ! $keepOpen], timeout: $timeout);
        $contextId = $context['browserContextId'];
        try {
            $target = $connection->call('Target.createTarget', [
                'url' => 'about:blank',
                'browserContextId' => $contextId,
            ], timeout: $timeout);
            $tab = self::attached($connection, $target['targetId'], $contextId, $timeout);
            $tab->session->send('Emulation.setDeviceMetricsOverride', [
                'width' => $width, 'height' => $height, 'deviceScaleFactor' => 1, 'mobile' => false,
            ]);
        } catch (CdpException $e) {
            $connection->call('Target.disposeBrowserContext', ['browserContextId' => $contextId], timeout: $timeout);

            throw $e;
        }

        return $tab;
    }

    /**
     * A page that is already open, such as one kept open by an earlier run. Nothing is navigated:
     * the page stays where it was.
     */
    public static function attach(CdpConnection $connection, string $targetId, ?float $timeout = null): self
    {
        $info = $connection->call('Target.getTargetInfo', ['targetId' => $targetId], timeout: $timeout);

        return self::attached($connection, $targetId, (string) ($info['targetInfo']['browserContextId'] ?? ''), $timeout);
    }

    /**
     * Stops driving the page and leaves it open.
     */
    public function detach(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->connection->call('Target.detachFromTarget', ['sessionId' => $this->session->sessionId]);
        }
    }

    /**
     * Disposes the page's browser context, taking its cookies and storage with it.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->connection->call('Target.disposeBrowserContext', ['browserContextId' => $this->contextId]);
    }

    private static function attached(CdpConnection $connection, string $targetId, string $contextId, ?float $timeout): self
    {
        $attached = $connection->call('Target.attachToTarget', ['targetId' => $targetId, 'flatten' => true], timeout: $timeout);

        return new self($connection, $targetId, $contextId, new PageSession($connection, $attached['sessionId'], $timeout));
    }
}
