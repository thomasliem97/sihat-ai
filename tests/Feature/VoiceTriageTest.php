<?php

use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Enums\TriageMessageRole;
use App\Enums\TriageRoleContext;
use App\Enums\TriageSessionStatus;
use App\Models\MedicalRecord;
use App\Models\TriageMessage;
use App\Models\TriageSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.modal.url' => 'https://modal.test',
        'services.openai.api_key' => 'test-openai-key',
        'services.triage.translate_model' => 'gpt-4o-mini',
        'services.triage.tts_model' => 'gpt-4o-mini-tts',
        'services.triage.tts_voice' => 'coral',
    ]);
});

function fakeTriageHttp(array $structuredOverrides = [], ?string $transcript = null, string $engine = 'medasr'): void
{
    $structured = array_merge([
        'assistant_message' => 'How long have you had these symptoms?',
        'urgency' => 'urgent',
        'chief_complaint' => 'chest pain',
        'summary' => 'Patient reports chest pain for two days.',
        'suggested_followups' => ['Any fever?', 'Any shortness of breath?'],
        'done' => false,
    ], $structuredOverrides);

    Http::fake([
        '*/api/v1/triage' => Http::response([
            'draft' => $structured['assistant_message'],
            'structured' => $structured,
        ], 200),
        '*/api/v1/transcribe' => Http::response([
            'transcript' => $transcript ?? 'I have chest pain',
            'engine' => $engine,
        ], 200),
        '*/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'translated text']]],
        ], 200),
        '*/audio/speech' => Http::response('fake-mp3', 200, [
            'Content-Type' => 'audio/mpeg',
        ]),
    ]);
}

test('patient can create a triage session and send multi-turn messages updating summary', function () {
    Http::fake([
        '*/api/v1/triage' => Http::sequence()
            ->push([
                'draft' => 'How long have you had these symptoms?',
                'structured' => [
                    'assistant_message' => 'How long have you had these symptoms?',
                    'urgency' => 'urgent',
                    'chief_complaint' => 'chest pain',
                    'summary' => 'Patient reports chest pain for two days.',
                    'suggested_followups' => ['Any fever?'],
                    'done' => false,
                ],
            ], 200)
            ->push([
                'draft' => 'Any radiation to the arm?',
                'structured' => [
                    'assistant_message' => 'Any radiation to the arm?',
                    'urgency' => 'urgent',
                    'chief_complaint' => 'chest pain',
                    'summary' => 'Chest pain with fever; asking about radiation.',
                    'suggested_followups' => [],
                    'done' => false,
                ],
            ], 200),
        '*/audio/speech' => Http::response('fake-mp3', 200, [
            'Content-Type' => 'audio/mpeg',
        ]),
    ]);

    $patient = User::factory()->patient()->create();

    $create = $this->actingAs($patient)->postJson(route('voice.triage.sessions.store'), [
        'locale' => 'en',
    ]);

    $create->assertCreated()
        ->assertJsonPath('session.status', 'active')
        ->assertJsonPath('session.subject_user_id', $patient->id);

    $sessionId = $create->json('session.id');

    $turn1 = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $sessionId),
        ['text' => 'I have chest pain and mild fever for two days'],
    );

    $turn1->assertSuccessful()
        ->assertJsonPath('session.urgency', 'urgent')
        ->assertJsonPath('session.summary', 'Patient reports chest pain for two days.')
        ->assertJsonPath('phases.thinking', true);

    expect($turn1->json('session.messages'))->toHaveCount(2);

    $turn2 = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $sessionId),
        ['text' => 'No radiation, just central pain'],
    );

    $turn2->assertSuccessful()
        ->assertJsonPath('session.summary', 'Chest pain with fever; asking about radiation.');

    expect($turn2->json('session.messages'))->toHaveCount(4);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/triage')) {
            return false;
        }
        $data = $request->data();

        return ($data['summary'] ?? null) === 'Patient reports chest pain for two days.'
            && ($data['user_message'] ?? null) === 'No radiation, just central pain'
            && ! str_contains((string) ($data['summary'] ?? ''), 'No radiation');
    });
});

test('medgemma triage request includes summary plus up to 10 recent turns not full dump', function () {
    fakeTriageHttp([
        'summary' => 'Running handoff blurb only.',
    ]);
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'role_context' => TriageRoleContext::Patient,
        'locale' => ReportLanguage::English,
        'summary' => 'Prior turn: mild headache.',
    ]);

    foreach (range(1, 12) as $i) {
        TriageMessage::factory()->create([
            'triage_session_id' => $session->id,
            'role' => $i % 2 === 1 ? TriageMessageRole::User : TriageMessageRole::Assistant,
            'content' => $i % 2 === 1 ? "User turn {$i}" : "Assistant turn {$i}",
        ]);
    }

    $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'Still aching behind my eyes'],
    )->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/triage')) {
            return false;
        }
        $data = $request->data();
        $recent = (string) ($data['recent_dialog'] ?? '');
        $lines = preg_split("/\r\n|\n|\r/", $recent) ?: [];

        return ($data['summary'] ?? null) === 'Prior turn: mild headache.'
            && ($data['user_message'] ?? null) === 'Still aching behind my eyes'
            && in_array('User: User turn 3', $lines, true)
            && in_array('Assistant: Assistant turn 12', $lines, true)
            && ! in_array('User: User turn 1', $lines, true)
            && ! in_array('User: User turn 2', $lines, true)
            && count($lines) === 10
            && ! array_key_exists('messages', $data)
            && ! array_key_exists('history', $data);
    });
});

test('non-english bridge translates in and out while storing locale messages only', function () {
    config(['services.openai.api_key' => 'test-openai-key']);

    $translateCalls = 0;
    Http::fake(function ($request) use (&$translateCalls) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            $data = $request->data();
            expect($data['user_message'] ?? null)->toBe('I have fever for three days');

            return Http::response([
                'draft' => 'How high is the fever?',
                'structured' => [
                    'assistant_message' => 'How high is the fever?',
                    'urgency' => 'urgent',
                    'chief_complaint' => 'fever',
                    'summary' => 'Patient reports fever for three days.',
                    'suggested_followups' => [],
                    'done' => false,
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            $translateCalls++;
            $content = $translateCalls === 1
                ? 'I have fever for three days'
                : 'Berapa tinggi demam anda?';

            return Http::response([
                'choices' => [['message' => ['content' => $content]]],
            ], 200);
        }

        if (str_contains($request->url(), 'audio/speech')) {
            return Http::response('fake-mp3', 200);
        }

        return Http::response(['error' => 'unexpected'], 500);
    });

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'role_context' => TriageRoleContext::Patient,
        'locale' => ReportLanguage::Malay,
    ]);

    $response = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'Saya demam tiga hari'],
    );

    $response->assertSuccessful();
    $messages = $response->json('session.messages');

    expect($messages)->toHaveCount(2)
        ->and($messages[0]['content'])->toBe('Saya demam tiga hari')
        ->and($messages[1]['content'])->toBe('Berapa tinggi demam anda?')
        ->and($translateCalls)->toBe(2);

    $session->refresh();
    expect($session->summary)->toBe('Patient reports fever for three days.');
});

test('voice turn posts locale language to stt endpoint', function () {
    fakeTriageHttp(transcript: 'Saya sakit dada', engine: 'whisper');
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => ReportLanguage::Malay,
    ]);

    $file = UploadedFile::fake()->createWithContent('triage.webm', 'fake-webm-bytes');

    $response = $this->actingAs($patient)->post(
        route('voice.triage.sessions.messages', $session),
        ['audio' => $file],
        ['Accept' => 'application/json'],
    );

    $response->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/transcribe')) {
            return false;
        }
        $data = $request->data();

        return ($data['language'] ?? null) === 'ms'
            && isset($data['audio_b64'])
            && $data['audio_b64'] !== '';
    });
});

test('patient share is visible to physicians and isolated from other patients', function () {
    $patient = User::factory()->patient()->create();
    $otherPatient = User::factory()->patient()->create();
    $physician = User::factory()->physician()->create();

    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
        'chief_complaint' => 'chest pain',
        'summary' => 'Shared handoff note',
    ]);

    $this->actingAs($patient)
        ->postJson(route('voice.triage.sessions.share', $session))
        ->assertSuccessful()
        ->assertJsonPath('session.status', 'active')
        ->assertJsonPath('session.shared_at', fn ($value) => filled($value));

    $session->refresh();

    expect($session->status)->toBe(TriageSessionStatus::Active)
        ->and($session->shared_at)->not->toBeNull();

    $this->actingAs($physician)
        ->getJson(route('voice.triage.sessions.show', $session))
        ->assertSuccessful()
        ->assertJsonPath('session.summary', 'Shared handoff note');

    $this->actingAs($otherPatient)
        ->getJson(route('voice.triage.sessions.show', $session))
        ->assertForbidden();

    $this->actingAs($physician)
        ->get(route('voice.triage'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('voice/Triage')
            ->has('sharedSessions', 1)
            ->where('sharedSessions.0.id', $session->id));
});

test('record context is injected when subject has completed records', function () {
    fakeTriageHttp();
    $patient = User::factory()->patient()->create();
    MedicalRecord::factory()->completed()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'title' => 'Prior chest x-ray',
        'status' => RecordStatus::Completed,
        'physician_report' => ['summary' => 'Mild cardiomegaly noted'],
    ]);

    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => ReportLanguage::English,
    ]);

    $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'Follow up on my breathing'],
    )->assertSuccessful();

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/triage')) {
            return false;
        }
        $ctx = (string) ($request->data()['record_context'] ?? '');

        return str_contains($ctx, 'Prior chest x-ray')
            && str_contains($ctx, 'Mild cardiomegaly noted');
    });
});

test('physician may create session without subject and message requires text or audio', function () {
    $physician = User::factory()->physician()->create();

    $create = $this->actingAs($physician)->postJson(route('voice.triage.sessions.store'), [
        'locale' => 'en',
    ]);

    $create->assertCreated()
        ->assertJsonPath('session.subject_user_id', null)
        ->assertJsonPath('session.role_context', 'physician');

    $this->actingAs($physician)
        ->postJson(route('voice.triage.sessions.messages', $create->json('session.id')), [])
        ->assertUnprocessable();
});

test('user can synthesize speech for any assistant message in a viewed session', function () {
    Http::fake([
        '*/audio/speech' => Http::response('replay-mp3', 200, [
            'Content-Type' => 'audio/mpeg',
        ]),
    ]);

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => ReportLanguage::English,
        'status' => TriageSessionStatus::Active,
    ]);

    $assistant = TriageMessage::factory()->create([
        'triage_session_id' => $session->id,
        'role' => TriageMessageRole::Assistant,
        'content' => 'Rest, hydrate, and monitor your fever.',
    ]);

    $userMessage = TriageMessage::factory()->create([
        'triage_session_id' => $session->id,
        'role' => TriageMessageRole::User,
        'content' => 'I have a fever',
    ]);

    $this->actingAs($patient)
        ->postJson(route('voice.triage.sessions.messages.speak', [
            'session' => $session,
            'message' => $assistant,
        ]))
        ->assertSuccessful()
        ->assertJsonPath('audio_base64', base64_encode('replay-mp3'));

    $this->actingAs($patient)
        ->postJson(route('voice.triage.sessions.messages.speak', [
            'session' => $session,
            'message' => $userMessage,
        ]))
        ->assertUnprocessable();
});
