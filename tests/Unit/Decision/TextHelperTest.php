<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Phox\BrowserAgent\Decision\ModelClient;
use Phox\BrowserAgent\Decision\ModelConfig;
use Phox\BrowserAgent\Decision\ModelException;
use Phox\BrowserAgent\Decision\Prompts;
use Phox\BrowserAgent\Decision\TextHelper;
use Phox\BrowserAgent\Decision\UnusableValue;
use Phox\BrowserAgent\Reader\Action;
use Tests\Fakes\FakeModels;

require_once __DIR__ . '/helpers.php';

function helperReplying(string $content, array &$sent = [], ?ModelConfig $config = null): TextHelper
{
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode(['choices' => [['message' => ['content' => $content]]]]))]));
    $stack->push(Middleware::history($sent));

    return new TextHelper(new ModelClient(new Client(['handler' => $stack])), $config ?? FakeModels::config());
}

function field(string $label, ?string $format = null, array $limits = []): Action
{
    return new Action(id: 'e1', kind: 'fill', label: $label, node: 1, role: 'textbox', value: '', format: $format, min: $limits['min'] ?? null, max: $limits['max'] ?? null);
}

it('asks for one field value with the goal, field, page and recent steps', function (): void {
    $sent = [];
    [$value, $spent] = helperReplying('{"text": "Fox"}', $sent)->value('Sign up as Fox', field('Name'), observed(), []);
    $body = json_decode((string) $sent[0]['request']->getBody(), true);

    expect($value)->toBe('Fox')
        ->and($spent['model'])->toBe('deepseek-chat')
        ->and($body['messages'][0]['content'])->toBe(Prompts::TEXT_VALUE)
        ->and(json_decode($body['messages'][1]['content'], true))->toBe([
            'goal' => 'Sign up as Fox',
            'field' => ['label' => 'Name', 'role' => 'textbox', 'value' => ''],
            'page' => ['title' => 'Form', 'text' => 'Page text'],
            'recent_actions' => [],
        ])
        ->and($body['response_format'])->toBe(['type' => 'json_object']);
});

it('tells the helper a value input format and limits', function (): void {
    $sent = [];
    helperReplying('{"text": "2026-09-24"}', $sent)->value('Book for 24 September 2026', field('Date', 'YYYY-MM-DD', ['min' => '2026-01-01']), observed(), []);
    $payload = json_decode(json_decode((string) $sent[0]['request']->getBody(), true)['messages'][1]['content'], true);

    expect($payload['field'])->toBe(['label' => 'Date', 'role' => 'textbox', 'value' => '', 'format' => 'YYYY-MM-DD', 'min' => '2026-01-01']);
});

it('reads a JSON object wrapped in a fence or a remark', function (): void {
    expect(helperReplying("Here you go:\n```json\n{\"text\": \"Fox\"}\n```")->value('g', field('Name'), observed(), [])[0])->toBe('Fox');
});

it('refuses a value that is missing, empty, too long or not in the field format, naming what came back', function (string $content, ?string $format): void {
    helperReplying($content)->value('g', field('Date', $format), observed(), []);
})->with([
    'null' => ['{"text": null}', null],
    'blank' => ['{"text": "  "}', null],
    'extra keys' => ['{"text": "a", "b": 1}', null],
    'too long' => [json_encode(['text' => str_repeat('a', 2001)]), null],
    'locale date' => ['{"text": "24/09/2026"}', 'YYYY-MM-DD'],
    'not JSON' => ['Fox', null],
])->throws(UnusableValue::class, 'Text helper returned no valid field value; nothing typed. Got:');

it('asks for several fields in one call and leaves out values that do not hold up', function (): void {
    $models = new FakeModels(values: ['Name' => 'Fox', 'Date' => '24/09/2026', 'Email' => null]);
    $helper = new TextHelper(new ModelClient($models->http), FakeModels::config());

    [$values, $spent] = $helper->values([
        'f0' => ['Enter the name', field('Name')],
        'f1' => ['Pick the date', field('Date', 'YYYY-MM-DD')],
        'f2' => ['Enter the email', field('Email')],
    ], observed(), []);

    expect($values)->toBe(['f0' => 'Fox'])
        ->and($spent['fields'])->toBe(1)
        ->and(count($models->textRequests()))->toBe(1)
        ->and($models->textRequests()[0]['messages'][0]['content'])->toBe(Prompts::TEXT_VALUES);
});

it('stops before asking when no text model key is configured', function (): void {
    helperReplying('{"text": "x"}', config: new ModelConfig(typesafeKey: 'k'))->value('g', field('Name'), observed(), []);
})->throws(ModelException::class, 'TYPE_TEXT needs a text model key');

it('switches the reasoning setting to what each provider accepts', function (): void {
    expect((new ModelConfig('k'))->reasoning())->toBe(['thinking' => ['type' => 'disabled']])
        ->and((new ModelConfig('k', textBaseUrl: 'https://openrouter.ai/api/v1'))->reasoning())->toBe(['reasoning' => ['effort' => 'low']])
        ->and((new ModelConfig('k', textReasoning: false))->reasoning())->toBe(['reasoning' => ['enabled' => false]]);
});
