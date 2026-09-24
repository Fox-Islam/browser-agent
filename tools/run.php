<?php

declare(strict_types=1);

/*
 * One agent run against a real browser endpoint and real models, printing its report. It costs
 * browser time and model calls, so it is a manual tool, not a test.
 *
 *   CDP_URL=wss://... CDP_AUTHORIZATION="Bearer ..." TYPESAFE_API_KEY=... TEXT_MODEL_API_KEY=... \
 *     php tools/run.php https://example.com "Find the contact page"
 *
 * TEXT_MODEL_BASE_URL and TEXT_MODEL override the text helper's provider and model.
 */

use GuzzleHttp\Client;
use Phox\BrowserAgent\Agent\Agent;
use Phox\BrowserAgent\Agent\AgentOptions;
use Phox\BrowserAgent\Agent\RunState;
use Phox\BrowserAgent\Cdp\BrowserTab;
use Phox\BrowserAgent\Cdp\WebSocketConnection;
use Phox\BrowserAgent\Decision\ModelConfig;
use Phox\BrowserAgent\Replay\Report;

require __DIR__ . '/../vendor/autoload.php';

[$url, $goal] = [$argv[1] ?? throw new RuntimeException('Usage: php tools/run.php <url> <goal>'), $argv[2] ?? throw new RuntimeException('Supply a goal')];
$env = fn (string $name, ?string $default = null) => getenv($name) ?: $default;

$connection = WebSocketConnection::connect(
    $env('CDP_URL') ?? throw new RuntimeException('Set CDP_URL'),
    $env('CDP_AUTHORIZATION') ? ['Authorization' => $env('CDP_AUTHORIZATION')] : [],
);
$tab = BrowserTab::open($connection);
$config = new ModelConfig(
    typesafeKey: $env('TYPESAFE_API_KEY') ?? throw new RuntimeException('Set TYPESAFE_API_KEY'),
    textKey: $env('TEXT_MODEL_API_KEY'),
    textBaseUrl: $env('TEXT_MODEL_BASE_URL', 'https://api.deepseek.com/v1'),
    textModel: $env('TEXT_MODEL', 'deepseek-chat'),
);
$options = new AgentOptions([$goal], heartbeat: function (RunState $state): void {
    $last = end($state->history);
    fprintf(STDERR, "%6dms %-9s %s\n", $state->elapsedMs, $state->status, $last ? "{$last->kind} {$last->label}" : '');
});

try {
    $state = Agent::make($tab->session, $config, new Client, $options)->run($url);
    echo json_encode(Report::of($state), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
} finally {
    $tab->close();
    $connection->close();
}
