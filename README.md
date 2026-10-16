# phox/browser-agent

Probably the fastest goal-driven browser agent for PHP.

Give it a URL and a goal in plain language. It drives a remote Chrome over the Chrome DevTools
Protocol, picks each action from the controls on the page in front of it, and returns what it did,
evidence of what it found, and the page's console errors and failed requests.

It runs against any CDP endpoint. Cloudflare Browser Run is the tested host: several processes can
share one warm browser session, each in its own browser context.

## Usage

```php
use GuzzleHttp\Client;
use Phox\BrowserAgent\Agent\Agent;
use Phox\BrowserAgent\Agent\AgentOptions;
use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\WebSocketConnection;
use Phox\BrowserAgent\Decision\ModelConfig;
use Phox\BrowserAgent\Replay\Report;

$connection = WebSocketConnection::connect($cdpUrl, ['Authorization' => "Bearer {$token}"]);
$tab = BrowserTab::open($connection);

$agent = Agent::make(
    $tab->session,
    new ModelConfig(typesafeKey: $typesafeKey, textKey: $textModelKey),
    new Client,
    new AgentOptions(['Find the contact form'], heartbeat: fn ($state) => $monitor->beat()),
);
$report = Report::of($agent->run('https://example.com'));

$tab->close();
```

Decisions go to TypeSafe's `/v1/systemone` by default. OpenRouter answers the same request at its
own path with an OpenRouter key:
`new ModelConfig(typesafeKey: $openRouterKey, typesafeBaseUrl: ModelConfig::OPENROUTER_BASE_URL, decisionPath: ModelConfig::OPENROUTER_DECISION_PATH)`.

`ModelConfig(images: ImageMode::Auto)` sends a decision a screenshot of the page, with each
offered element in view outlined and tagged with its index, where the page's text cannot settle
it: two or more unnamed controls side by side, a goal naming a colour, highlight, icon or
position, or a text-only answer that is BLOCKED, a DONE below 0.9 confidence or any other operation
below 0.6, which is asked again with the screenshot. `ImageMode::Always` sends one with every
decision. A screenshot costs about 2.5k input tokens and, on a hosted browser, a capture round
trip. A DONE below 0.5 confidence, or one the screenshot alone does not bear out, stops the run
`doubtful`.

Each decision is told what the last input caused: the site's own fetches that wrote something or
failed, with method, address and status, and the page's console errors and uncaught exceptions.
A save the page reports as done while its request failed, or while it logged an error, is then
not taken for success. Fetches to other sites, such as analytics and adverts, are left out.
`AgentOptions(signals: false)` turns this off. An unnamed control is described by the colour it is
filled with, so a set of swatches can be told apart.

Password and card fields are typed only from `AgentOptions(secrets: ['password' => ...])`: the
executor types the secret whose name the field's label contains, or the only one given. A secret
is never sent to a model, a value a page shows in clear is masked before a decision, and a step
records that a secret was typed, not which. Without a secret for it, a field is listed but cannot
be typed into. `new Replayer($open, secrets: new Secrets([...]))` types a secret step again.

A run is read-only unless `AgentOptions` sets `readOnly: false`: it may click to navigate or
reveal, but is offered nothing that types, selects, drags or submits. A run that may write can also
drag an element onto a drop zone, as a native drag or as pointer moves. A run ends `done`, `blocked` (with
`reason` `not_found` or `stuck`), `budget` or `off_site`. `Script::of($state)` records what it did
and `Replayer` plays that back without the model.

Besides what is on screen, a run is offered controls a row or card shows only under the pointer,
and controls out of view inside a panel that scrolls on its own; the executor hovers the one and
scrolls the other into view before pressing. An arriving page is read once its fetches have come
back and its loading indicators have cleared, waiting at most `SettleTimings::$arrival`.

`PageReader::readDocument()` reads the whole rendered page, with an outline of its headings, to
give a model the page as context, and `TextRendering::of($observation)` turns an observation into
that text. A document reading is cut so its rendering fits `max_document` characters, keeping
headings, then controls, then text, and nothing can be acted on from it.

`BrowserTab::open(..., keepOpen: true)` leaves the page open when the connection goes.
`BrowserTab::attach($connection, $targetId)` picks it up again, and `Agent::run()` without a url
works on it where it is, with goals, queries (`new AgentOptions([], $queries)`) or both.

Queries are the caller's JavaScript, run in the reader's isolated world: they see the DOM but not
page globals, and `el(node)` returns the element behind a node number from the latest observation,
as listed in `Report::of` and the text rendering. A query written as a function body, with
`return`, runs as the body of an async function.

## Requirements

- PHP 8.3
- A CDP endpoint, for example Cloudflare Browser Run
- A TypeSafe API key for action decisions
- An OpenAI-compatible model key for field values, when a run fills fields
- The gd extension, when decisions are sent screenshots

## Development

```bash
composer install
composer test
composer lint
```

Tests run offline and never call a browser host or a model API. The Browser suite drives a local
headless Chrome, found in the Puppeteer cache or through `CHROME_PATH`; skip it with
`vendor/bin/pest --testsuite=Unit`.

`tools/run.php` runs one goal against a real endpoint and real models and prints the report.
`tools/soak.php` runs hundreds of transport and reader calls against a real endpoint such as
Browser Run and reports latency and every failure. It costs browser time, so it is not a test.

The page reader is JavaScript in `reader/src`, bundled into `resources/page-reader.js`, which is
committed so the package installs without Node. Rebuild and test it in a local headless Chrome:

```bash
npm ci
npm test
```

`npm test` finds chrome-headless-shell in the Puppeteer cache, or uses `CHROME_PATH`.

## Licence

MIT
