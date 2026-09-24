<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Phox\BrowserAgent\Decision\ModelClient;
use Phox\BrowserAgent\Decision\ModelException;

function modelClient(array $responses, array &$sent = []): ModelClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($sent));

    return new ModelClient(new Client(['handler' => $stack]), timeout: 5.0, backoff: 0.0);
}

it('posts JSON with the key and a hard timeout on every request', function (): void {
    $sent = [];
    $answer = modelClient([new Response(200, [], '{"ok":true}')], $sent)->post('https://model.test/v1', 'secret', ['a' => 1]);

    expect($answer)->toBe(['ok' => true])
        ->and($sent[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer secret')
        ->and((string) $sent[0]['request']->getBody())->toBe('{"a":1}')
        ->and($sent[0]['options']['timeout'])->toBe(5.0);
});

it('asks an overloaded provider again, up to three times', function (int $status): void {
    $sent = [];

    expect(modelClient([new Response($status), new Response($status), new Response(200, [], '{"ok":1}')], $sent)->post('https://m.test', 'k', []))->toBe(['ok' => 1])
        ->and(count($sent))->toBe(3);
})->with([429, 503, 529]);

it('gives up on an overloaded provider after three attempts', function (): void {
    modelClient([new Response(429), new Response(429), new Response(429)])->post('https://m.test', 'k', []);
})->throws(ModelException::class, 'Model provider returned HTTP 429; no action executed.');

it('does not ask again after another error', function (): void {
    $sent = [];
    $client = modelClient([new Response(401), new Response(200, [], '{}')], $sent);

    expect(fn () => $client->post('https://m.test', 'k', []))->toThrow(ModelException::class, 'HTTP 401')
        ->and(count($sent))->toBe(1);
});

it('reports a connection failure without retrying it', function (): void {
    modelClient([new ConnectException('refused', new Request('POST', 'https://m.test'))])->post('https://m.test', 'k', []);
})->throws(ModelException::class, 'Model connection failed; no action executed.');

it('refuses a body that is not a JSON object', function (): void {
    modelClient([new Response(200, [], 'nope')])->post('https://m.test', 'k', []);
})->throws(ModelException::class, 'no action executed');
