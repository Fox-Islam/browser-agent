<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Phox\BrowserAgent\Decision\ActionSpace;
use Phox\BrowserAgent\Decision\Decider;
use Phox\BrowserAgent\Decision\DecisionClient;
use Phox\BrowserAgent\Decision\ImageMode;
use Phox\BrowserAgent\Decision\Looking;
use Phox\BrowserAgent\Decision\MarkedScreenshot;
use Phox\BrowserAgent\Decision\ModelConfig;
use Phox\BrowserAgent\Decision\ModelException;
use Phox\BrowserAgent\Decision\Prompts;
use Phox\BrowserAgent\Reader\Action;
use Phox\BrowserAgent\Reader\ScreenCapture;
use Tests\Fakes\FakeCdpSession;
use Tests\Fakes\FakeModels;

require_once __DIR__ . '/helpers.php';

function jpeg(int $width = 1120, int $height = 780): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

function screenDecider(FakeModels $models, ImageMode $mode, ?string $capture = null): Decider
{
    $cdp = new FakeCdpSession(['Page.captureScreenshot' => fn () => ['data' => base64_encode($capture ?? jpeg())]]);

    return new Decider(new DecisionClient($models->http, FakeModels::config($mode)), FakeModels::config($mode), screen: new ScreenCapture($cdp));
}

/** Two icon buttons side by side, named only by their role, and one named link. */
function iconRow(): array
{
    return [
        ['node' => 1, 'kind' => 'click', 'label' => 'button', 'group' => 9, 'rect' => ['x' => 1000, 'y' => 10, 'w' => 40, 'h' => 40]],
        ['node' => 2, 'kind' => 'click', 'label' => 'button', 'group' => 9, 'rect' => ['x' => 1050, 'y' => 10, 'w' => 40, 'h' => 40]],
        ['node' => 3, 'kind' => 'click', 'role' => 'link', 'label' => 'Products', 'rect' => ['x' => 100, 'y' => 10, 'w' => 120, 'h' => 30]],
    ];
}

/** @return list<Action> */
function actionsOf(array $controls): array
{
    return observed($controls)->actions;
}

it('looks up front for a row of unnamed controls or a goal that names how something looks', function (array $controls, string $goal, bool $wanted): void {
    expect(Looking::wanted(ImageMode::Auto, false, actionsOf($controls), $goal))->toBe($wanted);
})->with([
    'a row of unnamed controls' => [iconRow(), 'Open the cart', true],
    'a goal naming a colour' => [formControls(), 'Choose the red one', true],
    'a goal naming a position' => [formControls(), 'Open the tab right of the highlighted one', true],
    'one unnamed control alone' => [[['node' => 1, 'kind' => 'click', 'label' => 'button', 'group' => 9]], 'Open the cart', false],
    'an unnamed row out of view' => [array_map(fn ($c) => $c + ['offscreen' => 'panel'], iconRow()), 'Open the cart', false],
    'named controls and a plain goal' => [formControls(), 'Sign up as Fox', false],
]);

it('looks always or never whatever the page when the mode says so', function (): void {
    expect(Looking::wanted(ImageMode::Always, false, actionsOf(formControls()), 'Sign up'))->toBeTrue()
        ->and(Looking::wanted(ImageMode::Off, true, actionsOf(iconRow()), 'Choose the red one'))->toBeFalse();
});

it('looks again at a text-only BLOCKED, an unsure DONE or an unsure operation', function (string $operation, float $confidence, bool $again): void {
    expect(Looking::secondLook($operation, $confidence))->toBe($again);
})->with([
    ['BLOCKED', 0.95, true],
    ['DONE', 0.65, true],
    ['DONE', Looking::DONE_SURE, false],
    ['CLICK', 0.5, true],
    ['CLICK', Looking::UNCERTAIN, false],
]);

it('outlines offered elements in view on the screenshot and leaves their inside alone', function (): void {
    $space = ActionSpace::of(actionsOf(iconRow()));
    $marked = imagecreatefromstring((string) MarkedScreenshot::of(jpeg(), 1120, $space->targets));
    $green = fn (int $x, int $y) => (imagecolorat($marked, $x, $y) >> 8) & 0xFF;

    expect([imagesx($marked), imagesy($marked)])->toBe([1120, 780])
        ->and($green(1000, 40))->toBeLessThan(80)
        ->and($green(1020, 30))->toBeGreaterThan(240);
});

it('scales a screenshot too large for the API and sends none too small for it', function (): void {
    $targets = ActionSpace::of(actionsOf(iconRow()))->targets;
    $large = imagecreatefromstring((string) MarkedScreenshot::of(jpeg(2240, 1560), 1120, $targets));

    expect(max(imagesx($large), imagesy($large)))->toBe(MarkedScreenshot::MAX_SIDE)
        ->and(MarkedScreenshot::of(jpeg(200, 140), 1120, $targets))->toBeNull()
        ->and(MarkedScreenshot::of('not an image', 1120, $targets))->toBeNull();
});

it('places a select option where its element is and leaves out what is out of view', function (): void {
    $controls = [...formControls(), ['node' => 5, 'kind' => 'click', 'label' => 'Footer', 'offscreen' => 'panel']];

    expect(array_keys(MarkedScreenshot::places(ActionSpace::of(actionsOf($controls))->targets)))->toBe([1, 3, 4, 2]);
});

it('writes the screenshot into the JSON as an image_url data URI on OpenRouter', function (): void {
    $models = new FakeModels([['DONE']]);
    $config = new ModelConfig('k', typesafeBaseUrl: ModelConfig::OPENROUTER_BASE_URL, decisionPath: ModelConfig::OPENROUTER_DECISION_PATH, images: ImageMode::Always);
    $cdp = new FakeCdpSession(['Page.captureScreenshot' => fn () => ['data' => base64_encode(jpeg())]]);

    (new Decider(new DecisionClient($models->http, $config), $config, screen: new ScreenCapture($cdp)))->choose(observed(formControls()), 'Sign up', []);
    $request = $models->requests[0];

    expect($request['url'])->toBe('https://openrouter.ai/api/v1/systemone')
        ->and($request['images'])->toBe(0)
        ->and($request['imagesHeader'])->toBe('true')
        ->and($request['body'])->not->toHaveKey('assets')
        ->and($request['body']['state']['screen']['type'])->toBe('image_url')
        ->and($request['body']['state']['screen']['image_url']['url'])->toStartWith('data:image/jpeg;base64,');
});

it('refuses a decision path no provider answers at', function (): void {
    new ModelConfig('k', decisionPath: '/v2/elsewhere');
})->throws(InvalidArgumentException::class, 'No decision provider answers at /v2/elsewhere.');

it('uploads the marked screenshot with the opt-in header and asks whether it shows the goal done', function (): void {
    $models = new FakeModels([['DONE', 'screen' => 0.2]]);

    $decision = screenDecider($models, ImageMode::Always)->choose(observed(formControls()), 'Sign up', []);
    $request = $models->requests[0];

    expect($request['images'])->toBe(1)
        ->and($request['imagesHeader'])->toBe('true')
        ->and($request['body']['state']['screen'])->toMatch('/^<image:[0-9a-f]{32}>$/')
        ->and($request['body']['questions']['screen_done']['type'])->toBe('noul')
        ->and($request['body']['questions']['operation']['instructions'])->toContain(Prompts::SCREEN)
        ->and([$decision->screenshot, $decision->screenDone])->toBe([true, 0.2]);
});

it('sends no screenshot in auto mode when nothing calls for one', function (): void {
    $models = new FakeModels([['TYPE_TEXT', 'Name']]);

    $decision = screenDecider($models, ImageMode::Auto)->choose(observed(formControls()), 'Sign up as Fox', []);

    expect($models->requests[0]['images'])->toBe(0)
        ->and($models->requests[0]['imagesHeader'])->toBe('')
        ->and($models->requests[0]['body']['state'])->not->toHaveKey('screen')
        ->and([$decision->screenshot, $decision->firstLook])->toBe([false, null]);
});

it('asks an unsure text-only DONE again with the screenshot and keeps the first answer on it', function (): void {
    $models = new FakeModels([['DONE', 'confidence' => 0.65], ['BLOCKED']]);

    $decision = screenDecider($models, ImageMode::Auto)->choose(observed(formControls()), 'Sign up as Fox', []);

    expect(array_column($models->requests, 'images'))->toBe([0, 1])
        ->and([$decision->operation, $decision->screenshot])->toBe(['BLOCKED', true])
        ->and($decision->firstLook?->operation)->toBe('DONE');
});

it('keeps the text-only answer when the screenshot cannot be fitted to send', function (): void {
    $models = new FakeModels([['DONE', 'confidence' => 0.65]]);

    $decision = screenDecider($models, ImageMode::Auto, capture: jpeg(200, 140))->choose(observed(formControls()), 'Sign up as Fox', []);

    expect(count($models->requests))->toBe(1)
        ->and([$decision->operation, $decision->screenshot])->toBe(['DONE', false]);
});

it('reports a provider error, a dropped connection and a malformed answer as model failures', function (Response|Exception $reply, string $message): void {
    $http = new Client(['handler' => HandlerStack::create(new MockHandler([$reply, $reply, $reply]))]);

    (new DecisionClient($http, FakeModels::config(), backoff: 0.0))->ask(['page' => 'x'], ['q' => ['type' => 'noul', 'instructions' => 'Is it?']]);
})->with([
    'a status after its retries' => [new Response(429), 'Model provider returned HTTP 429; no action executed.'],
    'a status that is not retried' => [new Response(401), 'Model provider returned HTTP 401; no action executed.'],
    'a refused connection' => [new GuzzleHttp\Exception\ConnectException('refused', new GuzzleHttp\Psr7\Request('POST', 'https://typesafe.test')), 'Model connection failed; no action executed.'],
    'a body that is not JSON' => [new Response(200, ['Content-Type' => 'application/json'], 'not json'), 'Invalid TypeSafe response; no action executed.'],
])->throws(ModelException::class);

it('states in the README the thresholds the code uses', function (): void {
    $readme = preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 3) . '/README.md'));

    expect($readme)->toContain('a DONE below ' . Looking::DONE_SURE . ' confidence')
        ->toContain('any other operation below ' . Looking::UNCERTAIN)
        ->toContain('A DONE below ' . Phox\BrowserAgent\Agent\Agent::DOUBTFUL . ' confidence');
});
