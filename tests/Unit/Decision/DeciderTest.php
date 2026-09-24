<?php

declare(strict_types=1);

use Phox\BrowserAgent\Agent\Step;
use Phox\BrowserAgent\Decision\Decider;
use Phox\BrowserAgent\Decision\ModelClient;
use Phox\BrowserAgent\Decision\ModelException;
use Phox\BrowserAgent\Decision\Prompts;
use Tests\Fakes\FakeModels;

require_once __DIR__ . '/helpers.php';

function decider(FakeModels $models): Decider
{
    return new Decider(new ModelClient($models->http), FakeModels::config());
}

function scrollStep(bool $moved): Step
{
    $step = new Step(1, 'Scroll down', 'scroll', 'scroll_down', 'SCROLL_DOWN', 0.9, 0.9, null, 'https://example.test/', 0);
    $step->pageChanged = $moved;

    return $step;
}

it('asks every head in one request and acts only on the chosen operation target', function (): void {
    $models = new FakeModels([['TYPE_TEXT', 'Name']]);

    $decision = decider($models)->choose(observed(formControls()), 'Sign up as Fox', []);
    $questions = $models->decisionRequests()[0]['questions'];

    expect(count($models->requests))->toBe(1)
        ->and(array_keys($questions))->toBe(['operation', 'click_target', 'select_target'])
        ->and(array_keys($questions['operation']['criteria']))->toBe(['CLICK', 'TYPE_TEXT', 'SELECT', 'WAIT', 'DONE', 'BLOCKED'])
        ->and([$decision->choice, $decision->operation, $decision->target])->toBe(['e1', 'TYPE_TEXT', '1']);
});

it('takes a single candidate without asking a head for it', function (): void {
    $models = new FakeModels([['TYPE_TEXT']]);

    $decision = decider($models)->choose(observed(formControls()), 'Sign up', []);

    expect($decision->probabilities)->toBe(['e1' => $decision->operationProbabilities['TYPE_TEXT']]);
});

it('describes targets with their role, value and state and gives heads the full rules', function (): void {
    $models = new FakeModels([['CLICK', 'Send']]);

    decider($models)->choose(observed(formControls()), 'Sign up', []);
    $head = $models->decisionRequests()[0]['questions']['click_target'];

    expect($head['criteria']['3'])->toBe("Element [3], labelled 'Subscribe', a checkbox, checked: false.")
        ->and($models->decisionRequests()[0]['questions']['select_target']['criteria']['2:1'])->toBe("Element [2:1], labelled 'Size → Small', a combobox, currently holding 'Medium'.")
        ->and($head['instructions'])->toContain("Goal: Sign up\n\nOperation under consideration: CLICK\n\n" . Prompts::NEXT_ACTION . "\n\n" . Prompts::TARGET);
});

it('maps control operations and DONE to their ids', function (string $operation, string $choice): void {
    $models = new FakeModels([[$operation]]);

    expect(decider($models)->choose(observed(formControls()), 'Sign up', [])->choice)->toBe($choice);
})->with([['WAIT', 'wait'], ['DONE', 'DONE'], ['BLOCKED', 'BLOCKED']]);

it('refuses an invalid answer before anything is done', function (): void {
    $models = new FakeModels([['NOT_OFFERED']]);

    decider($models)->choose(observed(formControls()), 'Sign up', []);
})->throws(ModelException::class);

it('withholds BLOCKED and says how much is unseen while scrolling down can still move the page', function (): void {
    $models = new FakeModels([['WAIT'], ['WAIT'], ['WAIT']]);
    $long = observed(formControls(), ['y' => 0, 'height' => 3900, 'view' => 780]);

    decider($models)->choose($long, 'Find the contact form', []);
    decider($models)->choose($long, 'Find the contact form', [scrollStep(moved: true)]);
    decider($models)->choose($long, 'Find the contact form', [scrollStep(moved: false)]);
    [$fresh, $moved, $stuck] = array_map(fn ($r) => $r['questions']['operation'], $models->decisionRequests());

    expect($fresh['criteria'])->not->toHaveKey('BLOCKED')
        ->and($fresh['instructions'])->toContain('80% of this page is below the viewport and has not been seen.')
        ->and($moved['criteria'])->not->toHaveKey('BLOCKED')
        ->and($stuck['criteria'])->toHaveKey('BLOCKED');
});

it('keeps BLOCKED on a page with nothing below', function (): void {
    $models = new FakeModels([['WAIT']]);

    decider($models)->choose(observed(formControls()), 'Find it', []);

    expect($models->decisionRequests()[0]['questions']['operation']['criteria'])->toHaveKey('BLOCKED');
});

it('never offers a suppressed control', function (): void {
    $models = new FakeModels([['CLICK', 'Send']]);

    decider($models)->choose(observed(formControls()), 'Sign up', [], [], [['Subscribe', 'click']]);

    expect($models->decisionRequests()[0]['questions']['click_target']['criteria'])->not->toHaveKey('3');
});

it('asks a satisfaction check and a full decision per outstanding sub-goal, and reads them', function (): void {
    $models = new FakeModels([['TYPE_TEXT', 'plan' => [0 => 0.95, 1 => 0.05], 'holds' => [0 => ['CLICK', 'Open Name']]]]);

    $decision = decider($models)->choose(observed(formControls()), "Enter the name\nChoose large", [], ['Enter the name', 'Choose large']);
    $questions = $models->decisionRequests()[0]['questions'];

    expect(array_keys($questions))->toContain('plan0_satisfied', 'plan0_operation', 'plan0_click_target', 'plan1_select_target')
        ->and($questions['plan1_satisfied']['instructions'])->toBe(Prompts::STEP_SATISFIED . "\n\nStep: Choose large")
        ->and($decision->plan[0]->satisfied)->toBe(0.95)
        ->and($decision->plan[1]->satisfied)->toBe(0.05)
        ->and($decision->plan[0]->operation)->toBe('CLICK')
        ->and($decision->plan[0]->label)->toBe('Open Name');
});

it('reads a malformed sub-goal answer as unknown instead of failing the decision', function (): void {
    $models = new FakeModels([['WAIT', 'plan' => [0 => 7.0]]]);

    $decision = decider($models)->choose(observed(formControls()), 'Sign up', [], ['Sign up']);

    expect($decision->plan[0]->satisfied)->toBeNull();
});

it('sends the page, the element table and the recent steps as state', function (): void {
    $models = new FakeModels([['WAIT']]);

    decider($models)->choose(observed(formControls()), 'Sign up', [scrollStep(moved: true)]);
    $state = $models->decisionRequests()[0]['state'];

    expect($state['page'])->toBe(['url' => 'https://example.test/form', 'title' => 'Form', 'text' => 'Page text'])
        ->and(count($state['elements']))->toBe(4)
        ->and($state['recent_actions'])->toBe([['action' => 'Scroll down', 'kind' => 'scroll', 'text' => null, 'page_changed' => true]])
        ->and($models->decisionRequests()[0]['model'])->toBe('jev-latest');
});

it('explains unavailable elements to the decision and satisfaction questions only when there are some', function (): void {
    $models = new FakeModels([['WAIT'], ['WAIT']]);
    $controls = formControls();
    $marked = observed($controls)->withActions(array_map(fn ($a) => $a->label === 'Send' ? $a->unavailable() : $a, observed($controls)->actions));

    decider($models)->choose($marked, 'Check the Send button is visible', [], ['Check the Send button is visible']);
    decider($models)->choose(observed($controls), 'Check the Send button is visible', [], ['Check the Send button is visible']);
    [$with, $without] = array_map(fn ($r) => $r['questions'], $models->decisionRequests());

    expect($with['operation']['instructions'])->toEndWith(Prompts::NEXT_ACTION . "\n\n" . Prompts::UNAVAILABLE)
        ->and($with['click_target']['instructions'])->toContain(Prompts::UNAVAILABLE)
        ->and($with['plan0_satisfied']['instructions'])->toBe(Prompts::STEP_SATISFIED . "\n\n" . Prompts::UNAVAILABLE . "\n\nStep: Check the Send button is visible")
        ->and($with['plan0_operation']['instructions'])->toContain(Prompts::UNAVAILABLE)
        ->and(json_encode($without))->not->toContain('marked unavailable');
});
