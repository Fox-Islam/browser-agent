<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Executor;

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpSession;
use WeakMap;

/**
 * Presses at a point, travels to another and lets go, as either kind of drag needs. A
 * pointer-driven library (dnd-kit, react-beautiful-dnd) follows the moves. A native drag is begun
 * by the browser once the held pointer moves; with drags intercepted it is handed back as
 * Input.dragIntercepted instead of running in the operating system, which is the only way a
 * native drag can be finished over the protocol, and the drop is then dispatched at the end point.
 */
final class Drag
{
    /** Pointer moves from press to release. A library starts a drag past an activation distance. */
    private const int STEPS = 12;

    private const int PAUSE_MICROSECONDS = 30_000;

    /** @var WeakMap<CdpSession, true> sessions this drag listens to */
    private WeakMap $listening;

    /** @var array<string, mixed>|null the data of the native drag the browser handed back */
    private ?array $intercepted = null;

    public function __construct()
    {
        $this->listening = new WeakMap;
    }

    /**
     * @param  array{float, float}  $from
     * @param  array{float, float}  $to
     */
    public function perform(CdpSession $cdp, array $from, array $to): void
    {
        $this->listenTo($cdp);
        $this->intercepted = null;
        $this->intercept($cdp, true);
        try {
            $this->mouse($cdp, 'mousePressed', $from, 1);
            for ($step = 1; $step <= self::STEPS && $this->intercepted === null; $step++) {
                $this->mouse($cdp, 'mouseMoved', self::between($from, $to, $step / self::STEPS), 1);
                usleep(self::PAUSE_MICROSECONDS);
            }
            $this->drop($cdp, $to);
            $this->mouse($cdp, 'mouseReleased', $to, 0);
        } finally {
            $this->intercept($cdp, false);
        }
    }

    /**
     * @param  array{float, float}  $from
     * @param  array{float, float}  $to
     * @return array{float, float}
     */
    private static function between(array $from, array $to, float $fraction): array
    {
        return [$from[0] + ($to[0] - $from[0]) * $fraction, $from[1] + ($to[1] - $from[1]) * $fraction];
    }

    private function listenTo(CdpSession $cdp): void
    {
        if (! isset($this->listening[$cdp])) {
            $this->listening[$cdp] = true;
            $cdp->listen(function (string $method, array $params): void {
                if ($method === 'Input.dragIntercepted') {
                    $this->intercepted = $params['data'] ?? [];
                }
            });
        }
    }

    /**
     * @param  array{float, float}  $to
     */
    private function drop(CdpSession $cdp, array $to): void
    {
        if ($this->intercepted === null) {
            return;
        }
        foreach (['dragEnter', 'dragOver', 'drop'] as $type) {
            $cdp->send('Input.dispatchDragEvent', ['type' => $type, 'x' => $to[0], 'y' => $to[1], 'data' => $this->intercepted]);
            usleep(self::PAUSE_MICROSECONDS);
        }
    }

    private function intercept(CdpSession $cdp, bool $enabled): void
    {
        try {
            $cdp->send('Input.setInterceptDrags', ['enabled' => $enabled]);
        } catch (CdpException) {
            // Without interception a native drag is never handed back; pointer drags still work.
        }
    }

    /**
     * @param  array{float, float}  $at
     */
    private function mouse(CdpSession $cdp, string $type, array $at, int $buttons): void
    {
        $cdp->send('Input.dispatchMouseEvent', [
            'type' => $type, 'x' => $at[0], 'y' => $at[1], 'button' => 'left', 'buttons' => $buttons, 'clickCount' => 1,
        ]);
    }
}
