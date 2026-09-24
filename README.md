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

A run is read-only unless `AgentOptions` sets `readOnly: false`: it may click to navigate or
reveal, but is offered nothing that types, selects or submits. A run ends `done`, `blocked` (with
`reason` `not_found` or `stuck`), `budget` or `off_site`. `Script::of($state)` records what it did
and `Replayer` plays that back without the model.

`PageReader::readDocument()` reads the whole rendered page, with an outline of its headings, to
give a model the page as context, and `TextRendering::of($observation)` turns an observation into
that text. A document reading is cut so its rendering fits `max_document` characters, keeping
headings, then controls, then text, and nothing can be acted on from it.

`BrowserTab::open(..., keepOpen: true)` leaves the page open when the connection goes.
`BrowserTab::attach($connection, $targetId)` picks it up again, and `Agent::run()` without a url
works on it where it is, with goals, queries (`new AgentOptions([], $queries)`) or both.

Queries are the caller's JavaScript, run in the reader's isolated world: they see the DOM but not
page globals, and `el(node)` returns the element behind a node number from the latest observation,
as listed in `Report::of` and the text rendering.

## Requirements

- PHP 8.3
- A CDP endpoint, for example Cloudflare Browser Run
- A TypeSafe API key for action decisions
- An OpenAI-compatible model key for field values, when a run fills fields

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
