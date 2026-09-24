<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Replay;

use Closure;
use Phox\BrowserAgent\Agent\Settler;
use Phox\BrowserAgent\Agent\SettleTimings;
use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpSession;
use Phox\BrowserAgent\Executor\ExecutionStatus;
use Phox\BrowserAgent\Executor\Executor;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReaderOptions;

/**
 * Runs a script back without the model. Each step names a control, and the current page decides
 * which element that is, so a script survives a page laid out differently. A name
 * matching no control, or more than one, stops the replay instead of being guessed at.
 */
final readonly class Replayer
{
    /**
     * How many times a step is matched again when the page moves under it. A stale page means
     * nothing was sent, so looking again is safe; a step that keeps moving is a real failure.
     */
    public const int STEP_ATTEMPTS = 3;

    /**
     * How many times a whole script is taken again after the connection drops. A replay cannot
     * resume part way through a page that no longer exists, and repeating a script that types,
     * selects or submits would send its input twice, so only scripts that do none of those are.
     */
    public const int RECONNECTS = 1;

    private const int OFFERED_SHOWN = 12;

    /**
     * @param  Closure(): BrowserTab  $open  opens a tab on a live connection, connecting again when needed
     */
    public function __construct(
        private Closure $open,
        private ReaderOptions $reading = new ReaderOptions,
        private SettleTimings $timings = new SettleTimings,
    ) {}

    /**
     * Replays into a tab of its own, opened at the script's url and closed afterwards.
     *
     * @param  array<string, mixed>  $script
     * @param  Closure(array<string, mixed>): void|null  $onStep  called with each step's record
     * @return array{status: string, completed: int, steps: list<array<string, mixed>>, error?: string, repeatable?: bool}
     */
    public function replay(array $script, ?Closure $onStep = null): array
    {
        Script::validate($script);
        $repeatable = ! Script::mutates($script);
        for ($attempt = 0; ; $attempt++) {
            $done = [];
            try {
                $this->inNewTab($script, $onStep, $done);

                return ['status' => 'done', 'completed' => count($done), 'steps' => $done];
            } catch (CdpException $e) {
                if (! self::droppedConnection($e)) {
                    throw $e;
                }
                if (! $repeatable || $attempt === self::RECONNECTS) {
                    // The caller decides whether repeating these steps is safe, and a status
                    // alone does not show that the script types or submits.
                    return ['status' => 'connection_lost', 'completed' => count($done), 'steps' => $done, 'error' => mb_substr($e->getMessage(), 0, 200), 'repeatable' => $repeatable];
                }
            }
        }
    }

    /**
     * Replays into a page the caller already has open. A lost connection there is the caller's to
     * handle, so it is never taken again.
     *
     * @param  array<string, mixed>  $script
     * @param  Closure(array<string, mixed>): void|null  $onStep
     * @return array{status: string, completed: int, steps: list<array<string, mixed>>}
     */
    public function replayIn(CdpSession $session, array $script, ?Closure $onStep = null): array
    {
        Script::validate($script);
        $done = [];
        $this->steps($session, $script, $onStep, $done);

        return ['status' => 'done', 'completed' => count($done), 'steps' => $done];
    }

    /**
     * The one control on the page the step names.
     *
     * @param  array<string, mixed>  $step
     */
    private static function control(Observation $page, array $step, int $number): Action
    {
        $label = trim((string) ($step['label'] ?? ''));
        $kind = (string) ($step['kind'] ?? '');
        $found = array_values(array_filter($page->actions, fn (Action $a) => trim($a->label) === $label && $a->kind === $kind));
        if (count($found) === 1) {
            return $found[0];
        }
        $offered = array_unique(array_map(fn (Action $a) => trim($a->label), array_filter($page->actions, fn (Action $a) => $a->kind === $kind)));
        sort($offered);

        throw new ReplayException(sprintf(
            "Step %d (%s '%s') matched %d controls. This page offers: %s",
            $number, $kind, $label, count($found), implode(', ', array_slice($offered, 0, self::OFFERED_SHOWN)) ?: 'none of that kind',
        ));
    }

    private static function droppedConnection(CdpException $e): bool
    {
        return str_contains($e->getMessage(), 'CDP connection closed');
    }

    /**
     * @param  array<string, mixed>  $script
     * @param  list<array<string, mixed>>  $done
     */
    private function inNewTab(array $script, ?Closure $onStep, array &$done): void
    {
        $tab = ($this->open)();
        try {
            (new Settler(new PageReader($tab->session, $this->reading), $tab->session, $this->timings))->navigate($script['url']);
            $this->steps($tab->session, $script, $onStep, $done);
        } finally {
            try {
                $tab->close();
            } catch (CdpException) {
                // The connection went; a context opened with disposeOnDetach went with it.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $script
     * @param  list<array<string, mixed>>  $done
     */
    private function steps(CdpSession $session, array $script, ?Closure $onStep, array &$done): void
    {
        $reader = new PageReader($session, $this->reading);
        $settler = new Settler($reader, $session, $this->timings);
        $executor = new Executor($session, $reader, waitSeconds: 0.1);
        $started = microtime(true);
        $page = $settler->settle(stabilise: true);
        foreach (array_values($script['steps']) as $offset => $step) {
            $note = $this->step($executor, $settler, $page, $step, $offset + 1);
            $previous = $page;
            $page = $settler->settleAfter($page, (string) $step['kind']);
            $record = [
                'step' => $offset + 1,
                'kind' => $step['kind'],
                'label' => $step['label'],
                'text' => $step['text'] ?? null,
                'url' => $page->url,
                'page_changed' => $page->fingerprint !== $previous->fingerprint,
                'note' => $note,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            $done[] = $record;
            if ($onStep !== null) {
                $onStep($record);
            }
        }
    }

    /**
     * Carries out one step, matching it again on a page that moved before anything was sent.
     * Returns why the page holds something other than what was set, when it does.
     *
     * @param  array<string, mixed>  $step
     */
    private function step(Executor $executor, Settler $settler, Observation &$page, array $step, int $number): ?string
    {
        for ($attempt = 1; ; $attempt++) {
            $execution = $executor->execute($page, self::control($page, $step, $number), $step['text'] ?? null);
            if ($execution->status !== ExecutionStatus::Stale || $attempt === self::STEP_ATTEMPTS) {
                break;
            }
            $page = $settler->settle(stabilise: true);
        }

        return match ($execution->status) {
            ExecutionStatus::Stale => throw new ReplayException("Step {$number} kept changing under the replay: {$execution->reason}"),
            ExecutionStatus::Rejected => throw new ReplayException("Step {$number} was refused: {$execution->reason}"),
            default => $execution->reason,
        };
    }
}
