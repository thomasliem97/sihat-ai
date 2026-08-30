<?php

use App\Enums\Modality;
use App\Models\MedicalRecord;
use App\Models\RecordExplainerMessage;
use App\Models\User;
use App\Services\RecordExplainerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['services.modal.url' => 'https://modal.test']);
    Storage::fake('local');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function explainerRecord(User $owner, User $uploader, array $overrides = []): MedicalRecord
{
    Storage::disk('local')->put('medical-records/cxr.png', 'fake-image-bytes');

    return MedicalRecord::factory()->completed()->create(array_merge([
        'user_id' => $owner->id,
        'uploaded_by_user_id' => $uploader->id,
        'subject_user_id' => $owner->id,
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'file_path' => 'medical-records/cxr.png',
        'original_filename' => 'cxr.png',
        'mime_type' => 'image/png',
        'findings' => [
            ['label' => 'Right lower lobe opacity', 'description' => 'Patchy airspace opacity'],
        ],
        'guardrail_flags' => ['code' => 'ALLOW', 'flags' => ['medical_disclaimer_required']],
    ], $overrides));
}

test('physician can ask the scan on a completed imaging record', function () {
    Http::fake([
        '*/api/v1/explain' => Http::response([
            'answer' => 'The opacity sits in the right lower lobe.',
        ], 200),
    ]);

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, ['signed_at' => null]);

    $this->actingAs($physician)
        ->postJson(route('records.explain', $record), [
            'question' => 'Where is the opacity?',
            'finding_index' => 0,
        ])
        ->assertSuccessful()
        ->assertJsonPath('messages.0.role', 'user')
        ->assertJsonPath('messages.0.content', 'Where is the opacity?')
        ->assertJsonPath('messages.1.role', 'assistant')
        ->assertJsonPath('messages.1.content', 'The opacity sits in the right lower lobe.')
        ->assertJsonCount(4, 'suggestions');

    expect(RecordExplainerMessage::query()->where('medical_record_id', $record->id)->count())->toBe(2);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/explain')) {
            return false;
        }

        $data = $request->data();

        return ($data['question'] ?? null) === 'Where is the opacity?'
            && ($data['audience'] ?? null) === 'physician'
            && ($data['selected_finding_index'] ?? null) === 0;
    });
});

test('explain forwards the selected box including the ct slice index', function () {
    Http::fake([
        '*/api/v1/explain' => Http::response([
            'answer' => 'The lesion is on this slice.',
        ], 200),
    ]);

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, ['signed_at' => null]);

    $box = [
        'label' => 'Lesion',
        'x' => 0.2,
        'y' => 0.3,
        'width' => 0.15,
        'height' => 0.2,
        'kind' => 'finding',
        'image_index' => 3,
    ];

    $this->actingAs($physician)
        ->postJson(route('records.explain', $record), [
            'question' => 'What is this lesion?',
            'finding_index' => 1,
            'selected_box' => $box,
        ])
        ->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/explain')) {
            return false;
        }

        $data = $request->data();
        $sent = $data['selected_box'] ?? [];

        return ($data['selected_finding_index'] ?? null) === 1
            && (int) ($sent['image_index'] ?? -1) === 3
            && (float) ($sent['x'] ?? 0) === 0.2
            && ($sent['label'] ?? null) === 'Lesion';
    });
});

test('patient can ask the scan when the record is signed and not withheld', function () {
    Http::fake([
        '*/api/v1/explain' => Http::response([
            'answer' => 'This is the cloudy area in the lower right lung.',
        ], 200),
    ]);

    $patient = User::factory()->patient()->create();
    $physician = User::factory()->physician()->create();
    $record = explainerRecord($patient, $physician, [
        'signed_at' => now(),
        'signed_by' => $physician->id,
    ]);

    $this->actingAs($patient)
        ->postJson(route('records.explain', $record), [
            'question' => 'What does the cloudy area mean?',
        ])
        ->assertSuccessful()
        ->assertJsonPath('messages.1.role', 'assistant');
});

test('patient cannot ask the scan when the record is unsigned', function () {
    $patient = User::factory()->patient()->create();
    $physician = User::factory()->physician()->create();
    $record = explainerRecord($patient, $physician, ['signed_at' => null]);

    $this->actingAs($patient)
        ->postJson(route('records.explain', $record), [
            'question' => 'What does this mean?',
        ])
        ->assertForbidden();

    expect(RecordExplainerMessage::query()->count())->toBe(0);
});

test('patient cannot ask the scan when guardrails withhold the report', function () {
    $patient = User::factory()->patient()->create();
    $physician = User::factory()->physician()->create();
    $record = explainerRecord($patient, $physician, [
        'signed_at' => now(),
        'signed_by' => $physician->id,
        'guardrail_flags' => ['code' => 'WARN', 'flags' => ['critical_value_escalation']],
    ]);

    $this->actingAs($patient)
        ->postJson(route('records.explain', $record), [
            'question' => 'What does this mean?',
        ])
        ->assertForbidden();
});

test('patient cannot ask the scan on another patients record', function () {
    $owner = User::factory()->patient()->create();
    $other = User::factory()->patient()->create();
    $physician = User::factory()->physician()->create();
    $record = explainerRecord($owner, $physician, [
        'signed_at' => now(),
        'signed_by' => $physician->id,
    ]);

    $this->actingAs($other)
        ->postJson(route('records.explain', $record), [
            'question' => 'What does this mean?',
        ])
        ->assertForbidden();
});

test('completed record page exposes explainer for physicians', function () {
    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician);

    $this->actingAs($physician)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('records/Show')
            ->where('canExplain', true)
            ->has('explainerMessages')
            ->has('explainerSuggestions', 4)
            ->where('explainerSuggestions.0', 'What should I make of Right lower lobe opacity on this study?')
        );
});

test('signed patient show page uses patient explainer suggestions', function () {
    $patient = User::factory()->patient()->create();
    $physician = User::factory()->physician()->create();
    $record = explainerRecord($patient, $physician, [
        'signed_at' => now(),
        'signed_by' => $physician->id,
    ]);

    $this->actingAs($patient)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canExplain', true)
            ->has('explainerSuggestions', 4)
            ->where('explainerSuggestions.0', 'What does Right lower lobe opacity mean for me?')
        );
});

test('explain stream persists assembled tokens', function () {
    $sse = implode("\n\n", [
        'data: '.json_encode(['hop' => 'Decoded the study images', 'detail' => '1 image ready']),
        'data: '.json_encode(['hop' => 'Writing the answer', 'detail' => 'Where is the opacity?']),
        'data: '.json_encode(['token' => 'The ']),
        'data: '.json_encode(['token' => 'opacity sits in the right lower lobe.']),
        'data: '.json_encode(['done' => true, 'answer' => 'The opacity sits in the right lower lobe.']),
    ])."\n\n";

    Http::fake([
        '*/api/v1/explain/stream' => Http::response($sse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, ['signed_at' => null]);

    $response = $this->actingAs($physician)
        ->json('POST', route('records.explain', $record), [
            'question' => 'Where is the opacity?',
            'finding_index' => 0,
        ], [
            'Accept' => 'text/event-stream',
        ]);

    $response->assertSuccessful();

    $body = $response->streamedContent();

    expect($body)->toContain('"event":"user"')
        ->and($body)->toContain('"event":"hop"')
        ->and($body)->toContain('Reading this Chest X-ray')
        ->and($body)->toContain('Decoded the study images')
        ->and($body)->toContain('1 image ready')
        ->and($body)->toContain('Writing the answer')
        ->and($body)->toContain('"event":"token"')
        ->and($body)->toContain('"event":"suggestions"')
        ->and($body)->toContain('"event":"assistant"')
        ->and($body)->toContain('The opacity sits in the right lower lobe.');

    $messages = RecordExplainerMessage::query()
        ->where('medical_record_id', $record->id)
        ->orderBy('id')
        ->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->content)->toBe('Where is the opacity?')
        ->and($messages[1]->content)->toBe('The opacity sits in the right lower lobe.');
});

test('follow-up chips rotate after each answer', function () {
    $explainer = app(RecordExplainerService::class);
    $findings = [
        ['label' => 'Right lower lobe opacity'],
        ['label' => 'Cardiomegaly'],
    ];

    $first = $explainer->nextSuggestions(true, 'Where is the opacity?', 'It sits in the right lower lobe.', $findings);
    $second = $explainer->nextSuggestions(true, 'What else should I check besides the boxed region?', 'Look at the heart size as well.', $findings);

    expect($first)->toHaveCount(4)
        ->and($second)->toHaveCount(4)
        ->and($first)->not->toBe($second)
        ->and($first)->not->toContain('Where is the opacity?')
        ->and($second)->not->toContain('What else should I check besides the boxed region?');
});

test('head ct heart questions abstain without calling the model', function () {
    Http::fake();

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, [
        'signed_at' => null,
        'title' => 'Head CT',
        'modality' => Modality::Ct,
        'detected_modality' => Modality::Ct,
        'original_filename' => 'head-ct.png',
        'findings' => [
            ['label' => 'Brain parenchyma', 'description' => 'No acute intracranial hemorrhage'],
            ['label' => 'Calvarium', 'description' => 'No skull fracture identified'],
        ],
        'bounding_boxes' => [
            ['label' => 'BRAIN PARENCHYMA'],
        ],
    ]);

    $response = $this->actingAs($physician)
        ->postJson(route('records.explain', $record), [
            'question' => 'Is the heart size within normal limits here?',
        ])
        ->assertSuccessful();

    expect($response->json('messages.1.role'))->toBe('assistant')
        ->and($response->json('messages.1.content'))->toContain('does not include the chest')
        ->and($response->json('messages.1.content'))->toContain('the head');

    Http::assertNothingSent();
});

test('chest xray heart questions still go to the model', function () {
    Http::fake([
        '*/api/v1/explain' => Http::response([
            'answer' => 'Cardiomediastinal silhouette is within normal limits.',
        ], 200),
    ]);

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, ['signed_at' => null]);

    $this->actingAs($physician)
        ->postJson(route('records.explain', $record), [
            'question' => 'Is the heart size within normal limits here?',
        ])
        ->assertSuccessful()
        ->assertJsonPath('messages.1.content', 'Cardiomediastinal silhouette is within normal limits.');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/explain'));
});

test('head ct follow-up chips omit chest anatomy', function () {
    $explainer = app(RecordExplainerService::class);
    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $findings = [
        ['label' => 'Brain parenchyma'],
        ['label' => 'Calvarium'],
    ];
    $record = explainerRecord($patient, $physician, [
        'modality' => Modality::Ct,
        'detected_modality' => Modality::Ct,
        'findings' => $findings,
    ]);

    $chips = $explainer->nextSuggestions(
        true,
        'Where is the main finding on this film?',
        'The brain parenchyma looks unremarkable.',
        $findings,
        $record,
    );

    expect($chips)->toHaveCount(4)
        ->and($chips)->not->toContain('Is the heart size within normal limits here?')
        ->and($chips)->not->toContain('Are there signs I might be missing at the apices?');

    expect(collect($chips)->contains(
        fn (string $chip): bool => str_contains($chip, 'Brain parenchyma') || str_contains($chip, 'Calvarium'),
    ))->toBeTrue();
});

test('head ct page chips start from the study findings', function () {
    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, [
        'modality' => Modality::Ct,
        'detected_modality' => Modality::Ct,
        'findings' => [
            ['label' => 'Brain parenchyma'],
            ['label' => 'Ventricles and sulci'],
            ['label' => 'Paranasal sinuses and mastoid air cells'],
            ['label' => 'Calvarium'],
        ],
    ]);

    $this->actingAs($physician)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('explainerSuggestions', 4)
            ->where('explainerSuggestions.0', 'What should I make of Brain parenchyma on this study?')
            ->where('explainerSuggestions.1', 'What should I make of Ventricles and sulci on this study?')
            ->where('explainerSuggestions.2', 'What should I make of Paranasal sinuses and mastoid air cells on this study?')
            ->where('explainerSuggestions.3', 'What should I make of Calvarium on this study?')
        );
});

test('head ct stream refuses off-anatomy questions without calling modal', function () {
    Http::fake();

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, [
        'signed_at' => null,
        'modality' => Modality::Ct,
        'detected_modality' => Modality::Ct,
        'findings' => [
            ['label' => 'Brain parenchyma'],
            ['label' => 'Calvarium'],
        ],
    ]);

    $response = $this->actingAs($physician)
        ->json('POST', route('records.explain', $record), [
            'question' => 'Is the heart size within normal limits here?',
        ], [
            'Accept' => 'text/event-stream',
        ]);

    $response->assertSuccessful();

    $body = $response->streamedContent();

    expect($body)->toContain('Checking the question against this study')
        ->and($body)->toContain('does not include the chest')
        ->and($body)->toContain('"event":"assistant"');

    Http::assertNothingSent();
});

test('head ct titled without findings still refuses heart questions', function () {
    Http::fake();

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, [
        'signed_at' => null,
        'title' => 'Head CT',
        'modality' => Modality::Ct,
        'detected_modality' => Modality::Ct,
        'original_filename' => 'head-ct.png',
        'findings' => [],
    ]);

    $response = $this->actingAs($physician)
        ->postJson(route('records.explain', $record), [
            'question' => 'Is the heart size within normal limits here?',
        ])
        ->assertSuccessful();

    expect($response->json('messages.1.content'))->toContain('does not include the chest');

    Http::assertNothingSent();
});

test('chest xray brainstorm questions still go to the model', function () {
    Http::fake([
        '*/api/v1/explain' => Http::response([
            'answer' => 'Review the right lower lobe opacity next.',
        ], 200),
    ]);

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, ['signed_at' => null]);

    $this->actingAs($physician)
        ->postJson(route('records.explain', $record), [
            'question' => 'Can you help me brainstorm the next clinical step?',
        ])
        ->assertSuccessful()
        ->assertJsonPath('messages.1.content', 'Review the right lower lobe opacity next.');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/explain'));
});

test('explain stream discards truncated tokens after a modal error', function () {
    $sse = implode("\n\n", [
        'data: '.json_encode(['token' => 'The heart size looks ']),
        'data: '.json_encode(['error' => 'gpu reset']),
    ])."\n\n";

    Http::fake([
        '*/api/v1/explain/stream' => Http::response($sse, 200, [
            'Content-Type' => 'text/event-stream',
        ]),
    ]);

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = explainerRecord($patient, $physician, ['signed_at' => null]);

    $response = $this->actingAs($physician)
        ->json('POST', route('records.explain', $record), [
            'question' => 'Where is the opacity?',
        ], [
            'Accept' => 'text/event-stream',
        ]);

    $response->assertSuccessful();

    $body = $response->streamedContent();

    expect($body)->toContain('Explainer unavailable')
        ->and($body)->toContain('"event":"assistant"');

    $assistant = RecordExplainerMessage::query()
        ->where('medical_record_id', $record->id)
        ->where('role', 'assistant')
        ->first();

    expect($assistant?->content)->toContain('warming up')
        ->and($assistant?->content)->not->toContain('The heart size looks');
});
