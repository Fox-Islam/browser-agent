<?php

declare(strict_types=1);

namespace Phox\BrowserAgent\Reader;

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\CdpSession;

/**
 * Runs the page reader in an isolated world of the page's main frame, so page scripts can neither
 * see nor tamper with it. The bundle is installed once per document; each read is one
 * Runtime.evaluate.
 */
final class PageReader
{
    private const string WORLD = 'phox-page-reader';

    private const string BUNDLE = __DIR__ . '/../../resources/page-reader.js';

    private static ?string $bundle = null;

    private ?int $context = null;

    public function __construct(
        private readonly CdpSession $cdp,
        private readonly ReaderOptions $options = new ReaderOptions,
    ) {}

    /**
     * Null while the document has no body yet.
     */
    public function read(): ?Observation
    {
        return $this->readWith($this->options);
    }

    /**
     * The whole rendered page, to give a model as context.
     */
    public function readDocument(): ?Observation
    {
        return $this->readWith($this->options->withMode(ReadingMode::Document));
    }

    public function pageKey(): mixed
    {
        return $this->evaluate('pageReader.pageKey()', byValue: true)['value'] ?? null;
    }

    /**
     * Null when the control is no longer usable.
     */
    public function guard(int $node): mixed
    {
        return $this->evaluate("pageReader.guard({$node})", byValue: true)['value'] ?? null;
    }

    /**
     * The remote object id of the element behind a node handle, in the reader's world, or null
     * when the element has left the document.
     */
    public function resolve(int $node): ?string
    {
        return $this->evaluate("pageReader.resolve({$node})", byValue: false)['objectId'] ?? null;
    }

    /**
     * Why the control cannot be operated at a top-viewport point, or null when it can.
     */
    public function blocker(int $node, float $x, float $y): ?string
    {
        return $this->evaluate("pageReader.blocker({$node}, {$x}, {$y})", byValue: true)['value'] ?? null;
    }

    /**
     * Sets a select's or value input's value, fires input and change, and returns the value the
     * element holds afterwards; null when it has left the document. This mutates the page, so it
     * is never repeated: a lost context fails the call.
     */
    public function setValue(int $node, string $value): ?string
    {
        $argument = json_encode($value, JSON_THROW_ON_ERROR);

        return $this->evaluateInWorld("pageReader.setValue({$node}, {$argument})", byValue: true)['value'] ?? null;
    }

    /**
     * Runs a caller's query in the reader's world, where `el(node)` reaches an observed element and
     * page globals are out of sight. A promise is awaited. The answer is the value, or the text of
     * what the query threw.
     *
     * @return array{value: mixed}|array{exception: string}
     */
    public function query(string $expression, float $timeout): array
    {
        try {
            $response = $this->send($expression, byValue: true, awaitPromise: true, timeout: $timeout);
        } catch (CdpException $e) {
            return ['exception' => $e->getMessage()];
        }
        if (isset($response['exceptionDetails'])) {
            return ['exception' => (string) self::describe($response['exceptionDetails'])];
        }

        return ['value' => $response['result']['value'] ?? null];
    }

    /**
     * The rendered text inside a control, read from the element the reader holds. Never a field's
     * value, which the observation already carries with private values removed.
     */
    public function textOf(int $node): string
    {
        $expression = "(() => { const e = pageReader.resolve({$node}); return e ? (e.innerText || '') : ''; })()";

        return (string) ($this->evaluate($expression, byValue: true)['value'] ?? '');
    }

    /**
     * What the page is, whatever a run did to it: its title and first heading.
     *
     * @return array{title: string|null, h1: string|null}
     */
    public function identity(): array
    {
        $expression = "(() => { const h = document.querySelector('h1');"
            . " return {title: document.title || null, h1: h ? (h.innerText || '').trim().slice(0, 300) : null}; })()";
        $found = $this->evaluate($expression, byValue: true)['value'] ?? null;

        return ['title' => $found['title'] ?? null, 'h1' => $found['h1'] ?? null];
    }

    /**
     * Waits in the page until the window has scrolled away from $fromY and held still for two
     * frames, or until $timeoutMs passes, and returns where it ended up.
     */
    public function waitForScroll(int $fromY, int $timeoutMs): int
    {
        $expression = <<<JS
            new Promise((resolve) => {
                const began = performance.now();
                let last = scrollY, same = 0;
                const look = () => {
                    const moved = Math.round(scrollY) !== {$fromY};
                    same = moved && scrollY === last ? same + 1 : 0;
                    last = scrollY;
                    if (same >= 2 || performance.now() - began >= {$timeoutMs}) {
                        resolve(Math.round(scrollY));
                    } else {
                        setTimeout(look, 16);
                    }
                };
                look();
            })
            JS;

        return (int) ($this->evaluate($expression, byValue: true, awaitPromise: true, timeout: $timeoutMs / 1000 + 2)['value'] ?? $fromY);
    }

    /**
     * How long the document has gone without changing, in milliseconds.
     */
    public function quietMs(): float
    {
        return (float) ($this->evaluate('pageReader.quietMs()', byValue: true)['value'] ?? 0);
    }

    /**
     * Waits in the page until the document has been quiet for $stillMs, or $timeout seconds pass.
     * True when it held still. One round trip covers the whole wait.
     */
    public function holdsStill(int $stillMs, float $timeout): bool
    {
        $expression = sprintf('pageReader.still(%d, %d)', $stillMs, (int) round($timeout * 1000));
        $answer = $this->evaluate($expression, byValue: true, awaitPromise: true, timeout: $timeout + 2)['value'] ?? null;

        return is_array($answer) && ($answer['still'] ?? false) === true;
    }

    private static function bundle(): string
    {
        return self::$bundle ??= (string) file_get_contents(self::BUNDLE);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function describe(array $details): string
    {
        return $details['exception']['description'] ?? $details['text'] ?? 'unknown error';
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private static function result(array $response): array
    {
        if (isset($response['exceptionDetails'])) {
            throw new ReaderException('Page reader failed: ' . self::describe($response['exceptionDetails']));
        }

        return $response['result'];
    }

    private function readWith(ReaderOptions $reading): ?Observation
    {
        $options = json_encode($reading->toArray(), JSON_THROW_ON_ERROR);
        $result = $this->evaluate("pageReader.read({$options})", byValue: true);

        return ($result['value'] ?? null) === null ? null : Observation::fromArray($result['value']);
    }

    /**
     * Reading has no side effects on the page, so a call that lost its context to a navigation is
     * repeated once in a fresh world.
     *
     * @return array<string, mixed> the CDP RemoteObject
     */
    private function evaluate(string $expression, bool $byValue, bool $awaitPromise = false, ?float $timeout = null): array
    {
        return self::result($this->send($expression, $byValue, $awaitPromise, $timeout));
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluateInWorld(string $expression, bool $byValue): array
    {
        return self::result($this->sendOnce($expression, $byValue, false, null));
    }

    private function install(): int
    {
        $tree = $this->cdp->send('Page.getFrameTree');
        $world = $this->cdp->send('Page.createIsolatedWorld', [
            'frameId' => $tree['frameTree']['frame']['id'],
            'worldName' => self::WORLD,
        ]);
        $context = $world['executionContextId'];
        $response = $this->cdp->send('Runtime.evaluate', ['expression' => self::bundle(), 'contextId' => $context]);
        if (isset($response['exceptionDetails'])) {
            throw new ReaderException('Page reader failed to install: ' . self::describe($response['exceptionDetails']));
        }

        return $this->context = $context;
    }

    /**
     * The raw Runtime.evaluate response. A lost context means the expression never ran, so it is
     * sent once more in a fresh world.
     *
     * @return array<string, mixed>
     */
    private function send(string $expression, bool $byValue, bool $awaitPromise, ?float $timeout): array
    {
        try {
            return $this->sendOnce($expression, $byValue, $awaitPromise, $timeout);
        } catch (CdpException $e) {
            if (! $e->isLostContext()) {
                throw $e;
            }
            $this->context = null;

            return $this->sendOnce($expression, $byValue, $awaitPromise, $timeout);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sendOnce(string $expression, bool $byValue, bool $awaitPromise, ?float $timeout): array
    {
        $params = [
            'expression' => $expression,
            'contextId' => $this->context ?? $this->install(),
            'returnByValue' => $byValue,
        ];

        return $this->cdp->send('Runtime.evaluate', $awaitPromise ? [...$params, 'awaitPromise' => true] : $params, $timeout);
    }
}
