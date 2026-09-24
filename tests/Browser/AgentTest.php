<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\Agent;
use Phox\BrowserAgent\Agent\AgentOptions;
use Phox\BrowserAgent\Agent\RunState;
use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\CdpException;
use Phox\BrowserAgent\Cdp\WebSocketConnection;
use Phox\BrowserAgent\Decision\Prompts;
use Phox\BrowserAgent\Replay\Replayer;
use Phox\BrowserAgent\Replay\ReplayException;
use Phox\BrowserAgent\Replay\Report;
use Phox\BrowserAgent\Replay\Script;
use Tests\Browser\LocalChrome;
use Tests\Fakes\FakeModels;

beforeEach(function (): void {
    $this->chrome = new LocalChrome;
    $this->connection = WebSocketConnection::connect($this->chrome->webSocketUrl, timeout: 10.0);
    $this->tab = BrowserTab::open($this->connection);
});

afterEach(function (): void {
    $this->connection->close();
    $this->chrome->stop();
});

function runAgent(FakeModels $models, array $options, string $fixture): RunState
{
    $agent = Agent::make(test()->tab->session, FakeModels::config(), $models->http, new AgentOptions(...$options));

    return $agent->run(test()->chrome->base . '/' . $fixture);
}

const SIGN_UP = [['TYPE_TEXT', 'Name'], ['TYPE_TEXT', 'Email'], ['SELECT', 'Size → Large'], ['CLICK', 'Send'], ['DONE']];

it('fills a form, submits it and stops done, asking for every field value in one call', function (): void {
    $models = new FakeModels(SIGN_UP, ['Name' => 'Fox', 'Email' => 'fox@example.test']);
    $beats = [];

    $state = runAgent($models, ['goals' => ['Sign up as Fox, fox@example.test, size large'], 'readOnly' => false, 'heartbeat' => function (RunState $s) use (&$beats): void {
        $beats[] = count($s->history);
    }], 'signup.html');

    expect($state->status)->toBe('done')
        ->and(LocalChrome::run($this->tab->session, 'document.getElementById("thanks").textContent'))->toBe('Thanks, Fox <fox@example.test> size l')
        ->and(array_map(fn ($s) => [$s->kind, $s->label, $s->text], $state->history))->toBe([
            ['fill', 'Name', 'Fox'], ['fill', 'Email', 'fox@example.test'], ['select', 'Size → Large', null], ['click', 'Send', null],
        ])
        ->and(count($models->textRequests()))->toBe(1)
        ->and(count($models->decisionRequests()))->toBe(5)
        ->and($beats)->toBe([1, 2, 3, 4, 4])
        ->and($state->evidence['h1'])->toBe('Join the list')
        ->and($state->evidence['document_status'])->toBe(200)
        ->and($state->faults['document_type'])->toBe('text/html');
});

it('withholds BLOCKED until scrolling down stops moving the page, then reports not_found', function (): void {
    $models = new FakeModels([['BLOCKED', 'else' => 'SCROLL_DOWN']]);

    $state = runAgent($models, ['goals' => ['Find the pricing table'], 'queries' => ['document.title']], 'long.html');
    $offered = array_map(fn ($r) => array_key_exists('BLOCKED', $r['questions']['operation']['criteria']), $models->decisionRequests());

    expect($state->status)->toBe('blocked')
        ->and($state->reason)->toBe('not_found')
        ->and($offered[0])->toBeFalse()
        ->and(end($offered))->toBeTrue()
        ->and(array_unique(array_map(fn ($s) => $s->kind, $state->history)))->toBe(['scroll'])
        ->and($state->queries)->toBe([['value' => 'Long']]);
});

it('is read-only unless told otherwise, offering nothing that types, selects or submits', function (): void {
    $models = new FakeModels([['DONE']]);

    runAgent($models, ['goals' => ['Check the sign-up form']], 'signup.html');
    $request = $models->decisionRequests()[0];
    $elements = array_column($request['state']['elements'], null, 'label');

    expect(array_keys($request['questions']['operation']['criteria']))->not->toContain('TYPE_TEXT', 'SELECT')
        ->and(implode(' ', $request['questions']['click_target']['criteria']))->not->toContain("'Send'")
        ->and(implode(' ', $request['questions']['click_target']['criteria']))->toContain("'About'")
        ->and($elements['Send'])->toMatchArray(['operations' => [], 'available' => false])
        ->and($elements['Size'])->toMatchArray(['operations' => [], 'available' => false])
        ->and($elements['Name']['operations'])->toBe(['CLICK']);
});

it('stops off_site with the refused address when a link leaves the allowed hosts', function (): void {
    $models = new FakeModels([['CLICK', 'Elsewhere']]);

    $state = runAgent($models, ['goals' => ['Open the link'], 'allowedHosts' => ['127.0.0.1']], 'offsite.html');

    expect($state->status)->toBe('off_site')
        ->and($state->refusedUrl)->toStartWith('http://localhost:');
});

it('stops at its step budget instead of raising', function (): void {
    $models = new FakeModels([['CLICK', 'Go'], ['CLICK', 'Clicked 1']]);

    $state = runAgent($models, ['goals' => ['Press go twice'], 'maxSteps' => 1], 'execute.html');

    expect($state->status)->toBe('budget')
        ->and($state->history)->toHaveCount(1);
});

it('answers caller queries once the goals before them are satisfied', function (): void {
    $models = new FakeModels([['DONE']]);

    $state = runAgent($models, ['goals' => ['Look'], 'queries' => ['document.title', 'Promise.reject(new Error("nope"))']], 'signup.html');

    expect($state->queries[0])->toBe(['value' => 'Sign up'])
        ->and($state->queries[1]['exception'])->toContain('nope');
});

it('replays a run without the model into a fresh page', function (): void {
    $state = runAgent(new FakeModels(SIGN_UP, ['Name' => 'Fox', 'Email' => 'fox@example.test']), ['goals' => ['Sign up'], 'readOnly' => false], 'signup.html');
    $replayer = new Replayer(fn () => BrowserTab::open($this->connection));
    $seen = [];

    $result = $replayer->replay(Script::of($state), function (array $record) use (&$seen): void {
        $seen[] = $record['label'];
    });
    $second = BrowserTab::open($this->connection);
    $replayer->replayIn($second->session, ['version' => 1, 'url' => $this->chrome->base . '/signup.html', 'steps' => []]);

    expect($result['status'])->toBe('done')
        ->and($result['completed'])->toBe(4)
        ->and($seen)->toBe(['Name', 'Email', 'Size → Large', 'Send'])
        ->and(Report::of($state)['status'])->toBe('done');
});

it('stops a replay whose step names no control on the page', function (): void {
    $replayer = new Replayer(fn () => BrowserTab::open($this->connection));

    $replayer->replay(['version' => 1, 'url' => $this->chrome->base . '/signup.html', 'steps' => [['kind' => 'click', 'label' => 'Register']]]);
})->throws(ReplayException::class, "Step 1 (click 'Register') matched 0 controls. This page offers: About, Open Email, Open Name, Send");

it('takes a reading script again after the connection drops, but never one that types', function (): void {
    $drops = 1;
    $open = function () use (&$drops): BrowserTab {
        if ($drops-- > 0) {
            throw new CdpException('Target.createBrowserContext: CDP connection closed: the connection failed');
        }

        return BrowserTab::open($this->connection);
    };
    $reading = ['version' => 1, 'url' => $this->chrome->base . '/signup.html', 'steps' => [['kind' => 'click', 'label' => 'About']]];
    $typing = ['version' => 1, 'url' => $this->chrome->base . '/signup.html', 'steps' => [['kind' => 'fill', 'label' => 'Name', 'text' => 'Fox']]];

    $again = (new Replayer($open))->replay($reading);
    $drops = 1;
    $lost = (new Replayer($open))->replay($typing);

    expect($again['status'])->toBe('done')
        ->and($lost)->toMatchArray(['status' => 'connection_lost', 'completed' => 0, 'repeatable' => false]);
});

it('offers no form submitter to a read-only run whatever its label says', function (): void {
    $models = new FakeModels([['DONE']]);

    runAgent($models, ['goals' => ['Look at the buttons']], 'submits.html');
    $criteria = implode(' ', $models->decisionRequests()[0]['questions']['click_target']['criteria']);

    expect($criteria)->not->toContain("'Typeless'")
        ->and($criteria)->not->toContain("'Outside with form'")
        ->and($criteria)->toContain("'Plain button'")
        ->and($criteria)->toContain("'Outside without form'");
});

it('asks again when the page changed while the decision was on its way', function (): void {
    $models = new FakeModels([['CLICK', 'First'], ['CLICK', 'Second'], ['DONE']]);
    $models->latency = 0.3;

    $state = runAgent($models, ['goals' => ['Press the newest button']], 'arriving.html');

    expect(array_map(fn ($s) => $s->label, $state->history))->toBe(['Second'])
        ->and($state->decisionCalls)->toBe(3)
        ->and($state->decisions)->toHaveCount(2);
});

it('acts on a page that never holds still once waiting for it is futile, with one request', function (): void {
    $models = new FakeModels([['CLICK', 'Press'], ['DONE']]);
    $models->latency = 0.1;
    $started = microtime(true);

    $state = runAgent($models, ['goals' => ['Press it']], 'clock.html');
    $elapsed = microtime(true) - $started;

    expect(array_map(fn ($s) => $s->label, $state->history))->toBe(['Press'])
        ->and(LocalChrome::run($this->tab->session, 'document.querySelector("button").textContent'))->toBe('Pressed')
        ->and($state->decisionCalls)->toBe(2)
        ->and($elapsed)->toBeGreaterThan(1.5)
        ->and($elapsed)->toBeLessThan(6.0);
});

it('runs goals and queries on a kept page from another connection without navigating it', function (): void {
    $kept = BrowserTab::open($this->connection, keepOpen: true);
    $agent = Agent::make($kept->session, FakeModels::config(), (new FakeModels([['TYPE_TEXT', 'Name'], ['DONE']], ['Name' => 'Fox']))->http, new AgentOptions(['Enter the name Fox'], readOnly: false));
    $agent->run($this->chrome->base . '/signup.html');
    $this->connection->close();

    $again = WebSocketConnection::connect($this->chrome->webSocketUrl, timeout: 10.0);
    $attached = BrowserTab::attach($again, $kept->targetId);
    $models = new FakeModels;
    $state = Agent::make($attached->session, FakeModels::config(), $models->http, new AgentOptions([], ['document.querySelector("[name=name]").value', 'location.pathname']))->run();

    expect($state->status)->toBe('done')
        ->and($state->queries)->toBe([['value' => 'Fox'], ['value' => '/signup.html']])
        ->and($state->history)->toBe([])
        ->and($models->requests)->toBe([])
        ->and($attached->contextId)->toBe($kept->contextId);
    $attached->close();
    $again->close();
    $this->connection = WebSocketConnection::connect($this->chrome->webSocketUrl);
});

function offered(FakeModels $models, int $request): string
{
    $questions = $models->decisionRequests()[$request]['questions'];

    return implode(' ', $questions['click_target']['criteria'] ?? []) . ' ' . implode(' ', array_keys($questions['operation']['criteria']));
}

it('never offers a form submit again until a field on the form has changed', function (): void {
    $models = new FakeModels([['CLICK', 'Send'], ['DONE']]);

    $state = runAgent($models, ['goals' => ['Does the form refuse an empty submission?'], 'readOnly' => false], 'cf7.html');

    expect(offered($models, 0))->toContain("'Send'")
        ->and(offered($models, 1))->not->toContain("'Send'")
        ->and(array_column($models->decisionRequests()[1]['state']['elements'], null, 'label')['Send'])->toMatchArray(['available' => false])
        ->and(array_map(fn ($s) => $s->label, $state->history))->toBe(['Send'])
        ->and(LocalChrome::run($this->tab->session, 'window.submissions'))->toBe(1)
        ->and($state->status)->toBe('done');
});

it('offers the submit again once a fill on the form has changed a value', function (): void {
    $models = new FakeModels([['CLICK', 'Send'], ['TYPE_TEXT', 'Your name'], ['CLICK', 'Send'], ['DONE']], ['Your name' => 'Fox']);

    $state = runAgent($models, ['goals' => ['Send the form as Fox'], 'readOnly' => false], 'cf7.html');

    expect(array_map(fn ($s) => $s->label, $state->history))->toBe(['Send', 'Your name', 'Send'])
        ->and(offered($models, 3))->not->toContain("'Send'")
        ->and(LocalChrome::run($this->tab->session, 'window.submissions'))->toBe(2)
        ->and(LocalChrome::run($this->tab->session, 'window.sent'))->toBe(1);
});

it('withholds a script-driven button that keeps re-rendering the same result', function (): void {
    $models = new FakeModels([['CLICK', 'Check availability'], ['CLICK', 'Check availability'], ['DONE']]);

    runAgent($models, ['goals' => ['Check availability']], 'cf7.html');

    expect(offered($models, 1))->toContain("'Check availability'")
        ->and(offered($models, 2))->not->toContain("'Check availability'");
});

it('lets a read-only run confirm a submit button is on screen without pressing it', function (): void {
    $models = new FakeModels([['DONE']]);

    $state = runAgent($models, ['goals' => ['Scroll until the Send button is visible']], 'signup.html');

    expect($state->status)->toBe('done')
        ->and(array_column($models->decisionRequests()[0]['state']['elements'], 'label'))->toContain('Send')
        ->and(LocalChrome::run($this->tab->session, 'document.getElementById("thanks").textContent'))->toBe('');
});

it('ends done on a read-only run whose goal is to see a submit button it may not press', function (): void {
    $models = new FakeModels([['DONE', 'unless' => Prompts::UNAVAILABLE, 'otherwise' => 'BLOCKED']]);

    $state = runAgent($models, ['goals' => ['Scroll down until the Submit button is visible']], 'cf7.html');

    expect($state->status)->toBe('done')
        ->and(array_column($models->decisionRequests()[0]['state']['elements'], null, 'label')['Send'])->toMatchArray(['available' => false])
        ->and(LocalChrome::run($this->tab->session, 'window.submissions'))->toBe(0);
});

it('runs caller queries in the reader world, reaching controls from the report by node', function (): void {
    $kept = Agent::make($this->tab->session, FakeModels::config(), (new FakeModels([['DONE']]))->http, new AgentOptions(['Look']))
        ->run($this->chrome->base . '/signup.html');
    $about = array_values(array_filter(Report::of($kept)['final']['controls'], fn ($c) => $c['label'] === 'About'))[0];
    LocalChrome::run($this->tab->session, 'window.appState = {user: "fox"}');

    $state = Agent::make($this->tab->session, FakeModels::config(), (new FakeModels)->http, new AgentOptions([], [
        "el({$about['node']}).getAttribute('href')",
        'typeof window.appState',
    ]))->run();

    expect($state->queries)->toBe([['value' => '/names.html'], ['value' => 'undefined']]);
});

it('keeps the run so far when the caller stops it from the heartbeat', function (): void {
    $models = new FakeModels([['CLICK', 'Go'], ['CLICK', 'Clicked 1'], ['CLICK', 'Clicked 2']]);
    $agent = Agent::make($this->tab->session, FakeModels::config(), $models->http, new AgentOptions(['Keep pressing'], heartbeat: function (RunState $state): void {
        if (count($state->history) === 2) {
            throw new RuntimeException('Deadline reached');
        }
    }));

    expect($agent->state())->toBeNull()
        ->and(fn () => $agent->run($this->chrome->base . '/execute.html'))->toThrow(RuntimeException::class, 'Deadline reached')
        ->and(array_column(Report::of($agent->state())['steps'], 'label'))->toBe(['Go', 'Clicked 1']);
});
