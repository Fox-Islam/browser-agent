<?php

declare(strict_types=1);

use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\CdpConnection;
use Phox\BrowserAgent\Cdp\CdpException;

function recordingConnection(?string $failOn = null): CdpConnection
{
    return new class($failOn) implements CdpConnection
    {
        /** @var list<array{method: string, session: ?string, params: array<string, mixed>}> */
        public array $calls = [];

        public function __construct(private readonly ?string $failOn) {}

        public function call(string $method, array $params = [], ?string $sessionId = null, ?float $timeout = null): array
        {
            $this->calls[] = ['method' => $method, 'session' => $sessionId, 'params' => $params];

            return match (true) {
                $method === $this->failOn => throw new CdpException("{$method} failed"),
                $method === 'Target.createBrowserContext' => ['browserContextId' => 'C1'],
                $method === 'Target.createTarget' => ['targetId' => 'T1'],
                $method === 'Target.getTargetInfo' => ['targetInfo' => ['browserContextId' => 'C1']],
                $method === 'Target.attachToTarget' => ['sessionId' => 'S1'],
                default => [],
            };
        }

        public function listen(string $sessionId, Closure $listener): void {}

        public function close(): void {}
    };
}

it('enables focus emulation on the page session when it opens a tab', function (): void {
    $connection = recordingConnection();
    BrowserTab::open($connection);

    $focus = array_values(array_filter($connection->calls, fn (array $c) => $c['method'] === 'Emulation.setFocusEmulationEnabled'));

    expect($focus)->toHaveCount(1)
        ->and($focus[0]['session'])->toBe('S1')
        ->and($focus[0]['params'])->toBe(['enabled' => true]);
});

it('enables focus emulation on the page session when it attaches to an open tab', function (): void {
    $connection = recordingConnection();
    BrowserTab::attach($connection, 'T1');

    expect(array_column($connection->calls, 'method'))->toBe([
        'Target.getTargetInfo', 'Target.attachToTarget', 'Emulation.setFocusEmulationEnabled',
    ]);
});

it('disposes the context when enabling focus emulation fails on open', function (): void {
    $connection = recordingConnection('Emulation.setFocusEmulationEnabled');

    expect(fn () => BrowserTab::open($connection))->toThrow(CdpException::class);
    expect(array_column($connection->calls, 'method'))->toContain('Target.disposeBrowserContext');
});
