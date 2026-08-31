<?php

use App\Support\TriagePrompts;

test('normalizes valid prompts and drops junk', function () {
    $prompts = TriagePrompts::normalize([
        [
            'id' => 'Fever?',
            'question' => 'Any fever with this cough?',
            'allow_multiple' => false,
            'options' => [
                ['id' => 'yes', 'label' => 'Yes'],
                ['id' => 'no', 'label' => 'No'],
                ['id' => 'unsure', 'label' => 'Not sure'],
            ],
        ],
        ['question' => 'x'],
        [
            'question' => 'Any of these with the cough?',
            'allow_multiple' => true,
            'options' => [
                ['id' => 'phlegm', 'label' => 'Phlegm'],
                ['id' => 'wheeze', 'label' => 'Wheeze'],
            ],
        ],
        'not-an-array',
        [
            'question' => 'Fourth question should be dropped',
            'options' => [
                ['id' => 'a', 'label' => 'A'],
                ['id' => 'b', 'label' => 'B'],
            ],
        ],
        [
            'question' => 'Fifth question should also be dropped',
            'options' => [
                ['id' => 'a', 'label' => 'A'],
                ['id' => 'b', 'label' => 'B'],
            ],
        ],
    ]);

    expect($prompts)->toHaveCount(3)
        ->and($prompts[0]['id'])->toBe('fever')
        ->and($prompts[0]['options'])->toHaveCount(3)
        ->and($prompts[1]['allow_multiple'])->toBeTrue();
});

test('formats selected answers as a user message', function () {
    $prompts = TriagePrompts::normalize([
        [
            'id' => 'fever',
            'question' => 'Any fever?',
            'options' => [
                ['id' => 'yes', 'label' => 'Yes'],
                ['id' => 'no', 'label' => 'No'],
            ],
        ],
        [
            'id' => 'days',
            'question' => 'How long?',
            'options' => [
                ['id' => 'lt2d', 'label' => 'Less than 2 days'],
                ['id' => 'gt7d', 'label' => 'More than a week'],
            ],
        ],
    ]);

    expect(TriagePrompts::formatAnswers($prompts, [
        'fever' => ['yes'],
        'days' => ['lt2d'],
    ]))->toBe("Any fever?: Yes\nHow long?: Less than 2 days");
});

test('detects card answers as a follow-up payload', function () {
    expect(TriagePrompts::looksLikeAnswers(
        "Which symptoms do you have now?: Cough\nDo you have a condition that raises the risk?: No"
    ))->toBeTrue()
        ->and(TriagePrompts::looksLikeAnswers('I have a slight fever'))->toBeFalse();
});
