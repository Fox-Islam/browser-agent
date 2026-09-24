<?php

declare(strict_types=1);

use Phox\BrowserAgent\Decision\Choices;
use Phox\BrowserAgent\Decision\ModelException;

$valid = ['choice' => 'a', 'probabilities' => ['a' => 0.7, 'b' => 0.3], 'confidence' => 0.8];

it('accepts a choice that is most probable among exactly the offered ids', function () use ($valid): void {
    expect(Choices::validate($valid, ['a', 'b']))->toBe($valid);
});

it('rejects an unusable choice', function (mixed $answer): void {
    Choices::validate($answer, ['a', 'b']);
})->with([
    'not offered' => [['choice' => 'c', 'probabilities' => ['a' => 0.5, 'b' => 0.5], 'confidence' => 0.5]],
    'missing an id' => [['choice' => 'a', 'probabilities' => ['a' => 1.0], 'confidence' => 0.5]],
    'extra id' => [['choice' => 'a', 'probabilities' => ['a' => 0.5, 'b' => 0.3, 'c' => 0.2], 'confidence' => 0.5]],
    'not summing to one' => [['choice' => 'a', 'probabilities' => ['a' => 0.5, 'b' => 0.2], 'confidence' => 0.5]],
    'not the most probable' => [['choice' => 'b', 'probabilities' => ['a' => 0.7, 'b' => 0.3], 'confidence' => 0.5]],
    'probability above one' => [['choice' => 'a', 'probabilities' => ['a' => 1.2, 'b' => -0.2], 'confidence' => 0.5]],
    'no confidence' => [['choice' => 'a', 'probabilities' => ['a' => 0.7, 'b' => 0.3]]],
    'not an object' => ['a'],
])->throws(ModelException::class, 'Invalid TypeSafe response; no action executed.');
