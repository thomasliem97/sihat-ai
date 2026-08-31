<?php

use App\Support\TriageDraft;

test('detects a copied triage generation prompt', function () {
    $leak = <<<'TXT'
This is decision support only and does not replace clinical judgement. Confirm with a licensed clinician.
Who has the fever?: Adult
Write the next triage reply in English. Match the user's language. Stay in medical triage scope. If the user is off-topic, redirect briefly in fresh wording without answering the off-topic ask. For in-scope questions, give a full clinical reply rather than one sentence. Plain text only, not JSON.
TXT;

    expect(TriageDraft::isPromptEcho($leak))->toBeTrue()
        ->and(TriageDraft::scrub($leak))->toBe('');
});

test('detects a copied recent-dialog prompt body', function () {
    $leak = <<<'TXT'
Reports a slight fever; no temperature, duration, associated symptoms, medical history, or medication details provided yet.

Recent dialog (up to last 10 messages; prefer this for immediate continuity):
User: hello there
Assistant: hello there

Current user message:
What is your highest measured temperature?: Under 38°C
TXT;

    expect(TriageDraft::isPromptEcho($leak))->toBeTrue()
        ->and(TriageDraft::scrub($leak))->toBe('');
});

test('keeps a real clinical triage reply', function () {
    $reply = 'For a slight fever in an adult, paracetamol 500 mg to 1 g every 4 to 6 hours is a usual first option. Seek care if the fever lasts more than three days or breathing becomes hard.';

    expect(TriageDraft::isPromptEcho($reply))->toBeFalse()
        ->and(TriageDraft::scrub($reply))->toBe($reply);
});

test('strips ai disclaimers and keeps the clinical plan', function () {
    $draft = <<<'TXT'
For a fever under 38°C with only cough, this is likely viral and self-limiting. Rest, fluids, and paracetamol 500 mg to 1 g every 4 to 6 hours are usual first steps. However, I am an AI and cannot replace clinical judgement.
TXT;

    expect(TriageDraft::scrub($draft))->toBe(
        'For a fever under 38°C with only cough, this is likely viral and self-limiting. Rest, fluids, and paracetamol 500 mg to 1 g every 4 to 6 hours are usual first steps.'
    )
        ->and(TriageDraft::scrub($draft))->not->toContain('I am an AI');
});

test('strips a leading restatement of the user\'s card answers', function () {
    $user = "Which symptoms do you have now?: Cough\nDo you have a condition that raises the risk of complications?: No";
    $draft = "Cough\nNo\n\nFor a fever under 38°C with only cough, rest and fluids are usual first steps.";

    expect(TriageDraft::scrub($draft, $user))->toBe(
        'For a fever under 38°C with only cough, rest and fluids are usual first steps.'
    );
});

test('drops a see-a-doctor stub so a real plan can be written', function () {
    $stub = 'This is a low-grade fever. If you have a cough, sore throat, or shortness of breath, it is best to monitor your symptoms and contact a healthcare provider for further evaluation.';

    expect(TriageDraft::scrub($stub))->toBe('');
});

test('keeps a short assistant turn that is not a see-a-doctor stub', function () {
    expect(TriageDraft::scrub('Assistant turn 12'))->toBe('Assistant turn 12')
        ->and(TriageDraft::scrub('What brings you in today?'))->toBe('What brings you in today?');
});

test('prefers a structured plan over a fluent draft without one', function () {
    $draft = 'This is a low-grade fever starting today.';
    $plan = 'This is often viral. Paracetamol 500 mg to 1 g every 4 to 6 hours is the usual first option.';

    expect(TriageDraft::pickReply($draft, $plan))->toBe($plan)
        ->and(TriageDraft::pickReply($plan, $draft))->toBe($plan);
});
