<?php

declare(strict_types=1);

use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\WebSocketConnection;
use Phox\BrowserAgent\Executor\Execution;
use Phox\BrowserAgent\Executor\Executor;
use Phox\BrowserAgent\Reader\PageReader;
use Phox\BrowserAgent\Reader\ReaderOptions;
use Phox\BrowserAgent\Reader\TextRendering;
use Tests\Browser\LocalChrome;

beforeEach(function (): void {
    $this->chrome = new LocalChrome;
    $this->connection = WebSocketConnection::connect($this->chrome->webSocketUrl, timeout: 5.0);
    $this->tab = BrowserTab::open($this->connection);
    $this->reader = new PageReader($this->tab->session);
    $this->executor = new Executor($this->tab->session, $this->reader, waitSeconds: 0);
    LocalChrome::goto($this->tab->session, "{$this->chrome->base}/execute.html");
});

afterEach(function (): void {
    $this->connection->close();
    $this->chrome->stop();
});

function act(string $label, ?string $text = null): Execution
{
    $observation = test()->reader->read();
    foreach ($observation->actions as $action) {
        if ($action->label === $label) {
            return test()->executor->execute($observation, $action, $text);
        }
    }

    throw new RuntimeException("No action labelled {$label}");
}

function page(string $expression): mixed
{
    return LocalChrome::run(test()->tab->session, $expression);
}

it('clicks a button once', function (): void {
    expect(act('Go'))->toEqual(Execution::done())
        ->and(page('document.getElementById("go").textContent'))->toBe('Clicked 1');
});

it('replaces a text field value by typing', function (): void {
    expect(act('Name', 'Fox Islam'))->toEqual(Execution::done())
        ->and(page('document.getElementById("name").value'))->toBe('Fox Islam');
});

it('clears a text field', function (): void {
    act('Name', '');

    expect(page('document.getElementById("name").value'))->toBe('');
});

it('sets a date in its value format and fires change', function (): void {
    expect(act('When', '2026-09-24'))->toEqual(Execution::done())
        ->and(page('document.getElementById("when").value'))->toBe('2026-09-24')
        ->and(page('document.getElementById("log").textContent'))->toBe('when=2026-09-24;');
});

it('rejects a date the input would clear, leaving the page untouched', function (): void {
    expect(act('When', '2025-06-01'))->toEqual(Execution::rejected('2025-06-01 is below the minimum 2026-01-01'))
        ->and(page('document.getElementById("log").textContent'))->toBe('');
});

it('selects an option and fires change', function (): void {
    expect(act('Size → Medium'))->toEqual(Execution::done())
        ->and(page('document.getElementById("size").value'))->toBe('m')
        ->and(page('document.getElementById("log").textContent'))->toBe('size=m;');
});

it('scrolls with the mouse wheel', function (): void {
    act('Scroll down');
    $deadline = microtime(true) + 2;
    while (page('scrollY') < 560 && microtime(true) < $deadline) {
        usleep(20_000);
    }

    expect(page('scrollY'))->toBe(560);
});

it('does nothing when the control changed after the observation', function (): void {
    $observation = $this->reader->read();
    $go = $observation->actions[0];
    page('document.getElementById("go").textContent = "Delete account"');

    expect($this->executor->execute($observation, $go))->toEqual(Execution::stale('the control changed'))
        ->and(page('window.clicks'))->toBe(0);
});

it('does nothing when something covers the control', function (): void {
    $observation = $this->reader->read();
    page('document.body.insertAdjacentHTML("beforeend", "<div style=\"position:fixed;inset:0\"></div>")');

    expect($this->executor->execute($observation, $observation->actions[0]))->toEqual(Execution::stale('in the way: <div>'))
        ->and(page('window.clicks'))->toBe(0);
});

it('clicks a transparent control through the overlay standing in for it', function (): void {
    LocalChrome::goto($this->tab->session, "{$this->chrome->base}/transparent.html");

    expect(act('Dark mode'))->toEqual(Execution::done())
        ->and(page('document.querySelector("[aria-label=\"Dark mode\"]").checked'))->toBeTrue();
});

it('ticks a visually hidden checkbox through its label', function (): void {
    LocalChrome::goto($this->tab->session, "{$this->chrome->base}/hidden-inputs.html");

    expect(act('Accept terms'))->toEqual(Execution::done())
        ->and(page('document.querySelector("[name=terms]").checked'))->toBeTrue();
});

it('does nothing when the page scrolled after the observation', function (): void {
    $observation = $this->reader->read();
    page('scrollTo(0, 5)');

    expect($this->executor->execute($observation, $observation->actions[0]))->toEqual(Execution::stale('the page changed'));
});

it('reads the whole page in document mode and refuses to act on it', function (): void {
    LocalChrome::goto($this->tab->session, "{$this->chrome->base}/document.html");
    $document = $this->reader->readDocument();
    $far = array_values(array_filter($document->actions, fn ($a) => $a->label === 'Far button'))[0];

    expect($document->mode->value)->toBe('document')
        ->and($document->outline[0])->toBe(['level' => 1, 'text' => 'Top heading'])
        ->and($far->rect)->toBeNull()
        ->and($far->node)->toBeInt()
        ->and(fn () => $this->executor->execute($document, $far))->toThrow(InvalidArgumentException::class);
});

it('fits a document reading so the package rendering is within max_document, exactly', function (): void {
    LocalChrome::goto($this->tab->session, "{$this->chrome->base}/document.html");
    $whole = TextRendering::of($this->reader->readDocument());
    $budgets = [mb_strlen($whole), mb_strlen($whole) - 1, 600, 300];

    foreach ($budgets as $budget) {
        $reader = new PageReader($this->tab->session, new ReaderOptions(maxDocument: $budget));
        $observation = $reader->readDocument();

        expect(mb_strlen(TextRendering::of($observation)))->toBeLessThanOrEqual($budget);
    }
    $exact = (new PageReader($this->tab->session, new ReaderOptions(maxDocument: mb_strlen($whole))))->readDocument();

    expect(TextRendering::of($exact))->toBe($whole)
        ->and($exact->truncated)->toBe(['outline' => 0, 'text' => 0, 'actions' => 0]);
});
