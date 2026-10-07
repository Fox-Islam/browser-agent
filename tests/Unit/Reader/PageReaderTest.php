<?php

declare(strict_types=1);

use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Reader\Observation;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReaderException;
use Phox\BrowserAgent\Reader\ReaderOptions;
use Tests\Fakes\FakeCdpSession;

function observationPayload(): array
{
    return [
        'url' => 'https://example.test/',
        'title' => 'Example',
        'viewport' => ['w' => 1120, 'h' => 780],
        'scroll' => ['y' => 0, 'height' => 2000, 'view' => 780],
        'text' => 'Hello',
        'actions' => [
            ['id' => 'e1', 'node' => 7, 'kind' => 'click', 'role' => 'button', 'label' => 'Go', 'value' => '', 'rect' => ['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4]],
            ['id' => 'wait', 'kind' => 'wait', 'label' => 'Wait for the page to update'],
        ],
        'omitted_actions' => 0,
        'page_key' => 'k1',
        'guards' => ['7' => 'g7'],
    ];
}

function fakeBrowser(mixed $value = null, int $context = 11): FakeCdpSession
{
    return (new FakeCdpSession)
        ->on('Page.getFrameTree', fn () => ['frameTree' => ['frame' => ['id' => 'F1']]])
        ->on('Page.createIsolatedWorld', fn () => ['executionContextId' => $context])
        ->on('Runtime.evaluate', fn (array $params) => str_starts_with($params['expression'], 'pageReader.')
            ? ['result' => ['type' => 'object', 'value' => $value]]
            : ['result' => ['type' => 'undefined']]);
}

it('installs the bundle in an isolated world of the main frame on first use', function (): void {
    $cdp = fakeBrowser(observationPayload());

    (new PageReader($cdp))->read();

    expect($cdp->methods())->toBe(['Page.getFrameTree', 'Page.createIsolatedWorld', 'Runtime.evaluate', 'Runtime.evaluate'])
        ->and($cdp->calls[1]['params'])->toBe(['frameId' => 'F1', 'worldName' => 'phox-page-reader'])
        ->and($cdp->calls[2]['params']['expression'])->toBe(file_get_contents(__DIR__ . '/../../../resources/page-reader.js'))
        ->and($cdp->calls[2]['params']['contextId'])->toBe(11);
});

it('reads with one evaluate per call once installed', function (): void {
    $cdp = fakeBrowser(observationPayload());
    $reader = new PageReader($cdp);

    $reader->read();
    $cdp->calls = [];
    $reader->read();

    expect($cdp->methods())->toBe(['Runtime.evaluate'])
        ->and($cdp->calls[0]['params'])->toBe([
            'expression' => 'pageReader.read({"max_text":6000,"max_actions":250,"scroll_step":560,"max_options":25,"max_label":200,"mode":"viewport","max_document":50000,"max_outline":200})',
            'contextId' => 11,
            'returnByValue' => true,
        ]);
});

it('passes the caller options to the reader', function (): void {
    $cdp = fakeBrowser(observationPayload());

    (new PageReader($cdp, new ReaderOptions(maxText: 100, maxActions: 10, scrollStep: 50, maxOptions: 5, maxLabel: 60)))->read();

    expect($cdp->calls[3]['params']['expression'])
        ->toBe('pageReader.read({"max_text":100,"max_actions":10,"scroll_step":50,"max_options":5,"max_label":60,"mode":"viewport","max_document":50000,"max_outline":200})');
});

it('reads the whole page in document mode on request', function (): void {
    $cdp = fakeBrowser(observationPayload() + ['mode' => 'document']);

    (new PageReader($cdp))->readDocument();

    expect($cdp->calls[3]['params']['expression'])->toContain('"mode":"document"');
});

it('returns the observation', function (): void {
    $observation = (new PageReader(fakeBrowser(observationPayload())))->read();

    expect($observation)->toBeInstanceOf(Observation::class)
        ->and($observation->url)->toBe('https://example.test/')
        ->and($observation->guard(7))->toBe('g7');
});

it('returns null while the document has no body', function (): void {
    expect((new PageReader(fakeBrowser(null)))->read())->toBeNull();
});

it('reinstalls and repeats a read whose document was replaced', function (): void {
    $contexts = [11, 12];
    $lost = false;
    $cdp = fakeBrowser(observationPayload())
        ->on('Page.createIsolatedWorld', function () use (&$contexts): array {
            return ['executionContextId' => array_shift($contexts)];
        });
    $reader = new PageReader($cdp);
    $reader->read();
    $cdp->on('Runtime.evaluate', function (array $params) use (&$lost): array {
        if ($params['contextId'] === 11) {
            $lost = true;

            throw new CdpException('Cannot find context with specified id', -32000);
        }

        return ['result' => ['value' => str_starts_with($params['expression'], 'pageReader.') ? observationPayload() : null]];
    });
    $cdp->calls = [];

    expect($reader->read())->toBeInstanceOf(Observation::class)
        ->and($lost)->toBeTrue()
        ->and($cdp->methods())->toBe(['Runtime.evaluate', 'Page.getFrameTree', 'Page.createIsolatedWorld', 'Runtime.evaluate', 'Runtime.evaluate']);
});

it('does not repeat a call that failed for another reason', function (): void {
    $cdp = fakeBrowser()->on('Runtime.evaluate', fn () => throw new CdpException('Target closed', -32000));

    expect(fn () => (new PageReader($cdp))->pageKey())->toThrow(CdpException::class, 'Target closed')
        ->and(array_count_values($cdp->methods())['Runtime.evaluate'])->toBe(1);
});

it('reports an exception thrown inside the reader', function (): void {
    $cdp = fakeBrowser()->on('Runtime.evaluate', fn (array $params) => str_starts_with($params['expression'], 'pageReader.')
        ? ['result' => [], 'exceptionDetails' => ['exception' => ['description' => 'TypeError: boom']]]
        : ['result' => []]);

    expect(fn () => (new PageReader($cdp))->read())->toThrow(ReaderException::class, 'Page reader failed: TypeError: boom');
});

it('reports a bundle that fails to install', function (): void {
    $cdp = fakeBrowser()->on('Runtime.evaluate', fn () => ['result' => [], 'exceptionDetails' => ['text' => 'SyntaxError']]);

    expect(fn () => (new PageReader($cdp))->read())->toThrow(ReaderException::class, 'Page reader failed to install: SyntaxError');
});

it('recomputes the page key and a guard in the page', function (): void {
    $cdp = fakeBrowser('v');
    $reader = new PageReader($cdp);

    expect($reader->pageKey())->toBe('v')
        ->and($reader->guard(42))->toBe('v')
        ->and(array_column(array_column($cdp->calls, 'params'), 'expression'))
        ->toContain('pageReader.pageKey()', 'pageReader.guard(42)');
});

it('resolves a node handle to a remote object in the reader world', function (): void {
    $cdp = fakeBrowser()->on('Runtime.evaluate', fn (array $params) => match ($params['expression']) {
        'pageReader.resolve(3)' => ['result' => ['type' => 'object', 'objectId' => 'obj-3']],
        'pageReader.resolve(4)' => ['result' => ['type' => 'object', 'subtype' => 'null', 'value' => null]],
        default => ['result' => []],
    });
    $reader = new PageReader($cdp);

    expect($reader->resolve(3))->toBe('obj-3')
        ->and($reader->resolve(4))->toBeNull()
        ->and(end($cdp->calls)['params']['returnByValue'])->toBeFalse();
});

it('runs a query written as a function body as the body of an async function', function (): void {
    $cdp = (new FakeCdpSession)
        ->on('Page.getFrameTree', fn () => ['frameTree' => ['frame' => ['id' => 'F1']]])
        ->on('Page.createIsolatedWorld', fn () => ['executionContextId' => 11])
        ->on('Runtime.evaluate', fn (array $params) => match (true) {
            str_starts_with($params['expression'], '(async () => {') => ['result' => ['value' => 3]],
            $params['expression'] === 'const n = 3; return n' => ['exceptionDetails' => ['exception' => ['description' => 'SyntaxError: Illegal return statement']]],
            default => ['result' => ['type' => 'undefined']],
        });

    expect((new PageReader($cdp))->query('const n = 3; return n', 5.0))->toBe(['value' => 3])
        ->and(end($cdp->calls)['params']['expression'])->toBe("(async () => {\nconst n = 3; return n\n})()");
});

it('reports a query error that is not about its form as it came', function (): void {
    $cdp = fakeBrowser();
    $cdp->on('Runtime.evaluate', fn (array $params) => str_starts_with($params['expression'], 'nope')
        ? ['exceptionDetails' => ['exception' => ['description' => 'ReferenceError: nope is not defined']]]
        : ['result' => ['type' => 'undefined']]);

    expect((new PageReader($cdp))->query('nope', 5.0))->toBe(['exception' => 'ReferenceError: nope is not defined']);
});

/**
 * A page whose reads offer a drag source, and whose own world finds one drop handler.
 */
function draggingPage(): FakeCdpSession
{
    $payload = observationPayload();
    $payload['actions'][0]['kind'] = 'drag';

    return fakeBrowser($payload)
        ->on('Runtime.evaluate', fn (array $params) => match (true) {
            str_starts_with($params['expression'], 'pageReader.') => ['result' => ['type' => 'object', 'value' => $payload]],
            str_contains($params['expression'], '__reactProps$') => ['result' => ['type' => 'object', 'objectId' => 'found']],
            default => ['result' => ['type' => 'undefined']],
        })
        ->on('Runtime.getProperties', fn () => ['result' => [['name' => '0', 'value' => ['objectId' => 'main-1']], ['name' => 'length', 'value' => ['value' => 1]]]])
        ->on('DOM.describeNode', fn () => ['node' => ['backendNodeId' => 42]])
        ->on('DOM.resolveNode', fn () => ['object' => ['objectId' => 'world-1']])
        ->on('Runtime.callFunctionOn', fn () => ['result' => ['value' => 1]]);
}

it('hands the drop handlers only page script can see to the reader, then reads again', function (): void {
    $cdp = draggingPage();

    (new PageReader($cdp))->read();
    $handOver = array_values(array_filter($cdp->calls, fn ($c) => $c['method'] === 'Runtime.callFunctionOn'))[0]['params'];
    $resolve = array_values(array_filter($cdp->calls, fn ($c) => $c['method'] === 'DOM.resolveNode'))[0]['params'];
    $reads = array_filter($cdp->calls, fn ($c) => str_starts_with($c['params']['expression'] ?? '', 'pageReader.read('));

    expect($resolve)->toMatchArray(['backendNodeId' => 42, 'executionContextId' => 11])
        ->and($handOver['executionContextId'])->toBe(11)
        ->and($handOver['arguments'])->toBe([['objectId' => 'world-1']])
        ->and(count($reads))->toBe(2)
        ->and($cdp->methods())->toContain('Runtime.releaseObjectGroup');
});

it('looks for drop handlers once per address, and never on a page with nothing to drag', function (): void {
    $dragging = draggingPage();
    $reader = new PageReader($dragging);
    $reader->read();
    $reader->read();
    $plain = fakeBrowser(observationPayload());
    (new PageReader($plain))->read();

    expect(count(array_filter($dragging->calls, fn ($c) => $c['method'] === 'Runtime.callFunctionOn')))->toBe(1)
        ->and($plain->methods())->not->toContain('Runtime.getProperties');
});
