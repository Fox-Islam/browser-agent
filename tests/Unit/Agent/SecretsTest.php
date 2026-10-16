<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\Secrets;
use Phox\BrowserAgent\Reader\Observation;

require_once dirname(__DIR__) . '/Decision/helpers.php';

function signUp(string $text = 'Create your account'): Observation
{
    return observed([
        ['node' => 1, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Email'],
        ['node' => 2, 'kind' => 'fill', 'role' => 'textbox', 'label' => 'Password', 'secret' => true],
        ['node' => 3, 'kind' => 'click', 'label' => 'Create my account'],
    ], text: $text);
}

it('gives a field the secret its label names, or the only one there is', function (string $label, array $values, ?string $found): void {
    expect((new Secrets($values))->for($label))->toBe($found);
})->with([
    ['Password', ['password' => 'a'], 'a'],
    ['Confirm password', ['password' => 'a', 'pin' => 'b'], 'a'],
    ['Passcode', ['only' => 'c'], 'c'],
    ['Passcode', ['password' => 'a', 'pin' => 'b'], null],
    ['Password', [], null],
]);

it('lists a secret field it has nothing for without offering it', function (): void {
    $without = (new Secrets)->offered(signUp());
    $with = (new Secrets(['password' => 'hunter2']))->offered(signUp());

    expect(array_map(fn ($a) => [$a->label, $a->available], array_slice($without->actions, 0, 3)))->toBe([['Email', true], ['Password', false], ['Create my account', true]])
        ->and($with->actions[1]->available)->toBeTrue();
});

it('replaces every secret a page shows before a model sees it, and keeps the fingerprint', function (): void {
    $page = signUp('Your password is hunter2');
    $page = $page->withActions([...array_slice($page->actions, 0, 1), Phox\BrowserAgent\Reader\Action::fromArray(['id' => 'e9', 'kind' => 'fill', 'label' => 'Password', 'value' => 'hunter2', 'node' => 2]), ...array_slice($page->actions, 1)]);
    $scrubbed = (new Secrets(['password' => 'hunter2']))->scrub($page);

    expect($scrubbed->text)->toBe('Your password is <secret>')
        ->and($scrubbed->actions[1]->value)->toBe('<secret>')
        ->and(json_encode($scrubbed))->not->toContain('hunter2')
        ->and($scrubbed->fingerprint)->toBe($page->fingerprint);
});
