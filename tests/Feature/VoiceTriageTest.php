<?php

use App\Enums\RecordStatus;
use App\Enums\TriageMessageRole;
use App\Enums\TriageRoleContext;
use App\Enums\TriageSessionStatus;
use App\Jobs\ProcessTriageVoiceTurn;
use App\Models\MedicalRecord;
use App\Models\TriageMessage;
use App\Models\TriageSession;
use App\Models\User;
use App\Services\TriageTurnStatus;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.modal.url' => 'https://modal.test',
        'services.openai.api_key' => 'test-openai-key',
        'services.triage.detect_model' => 'gpt-4o-mini',
        'services.triage.intent_model' => 'gpt-4o-mini',
        'services.triage.intent_confidence_min' => 0.85,
        'services.triage.tts_model' => 'gpt-4o-mini-tts',
        'services.triage.tts_voice' => 'marin',
    ]);
});

/**
 * @return array{code?: string, name?: string, intent?: string, confidence?: float, reason?: string}
 */
function openaiJsonFromRequest($request): array
{
    $messages = $request->data()['messages'] ?? [];
    $system = (string) data_get($messages, '0.content', '');

    if (str_contains($system, 'classify messages for SihatAI Voice Triage')) {
        return [
            'intent' => 'triage',
            'confidence' => 0.95,
            'reason' => 'clinical',
        ];
    }

    return [
        'code' => 'en',
        'name' => 'English',
    ];
}

function fakeOpenAiChatCompletion($request, ?callable $intentOverride = null): PromiseInterface
{
    $messages = $request->data()['messages'] ?? [];
    $system = (string) data_get($messages, '0.content', '');

    if (str_contains($system, 'classify messages for SihatAI Voice Triage')) {
        $payload = $intentOverride
            ? $intentOverride($request)
            : [
                'intent' => 'triage',
                'confidence' => 0.95,
                'reason' => 'clinical',
            ];
    } else {
        $payload = openaiJsonFromRequest($request);
        if (isset($payload['intent'])) {
            $payload = ['code' => 'en', 'name' => 'English'];
        }
    }

    return Http::response([
        'choices' => [['message' => ['content' => json_encode($payload)]]],
    ], 200);
}

function fakeTriageHttp(array $structuredOverrides = [], ?string $transcript = null, string $engine = 'medasr', ?string $draft = null): void
{
    $structured = array_merge([
        'assistant_message' => 'How long have you had these symptoms?',
        'urgency' => 'urgent',
        'chief_complaint' => 'chest pain',
        'summary' => 'Patient reports chest pain for two days.',
        'done' => false,
        'in_scope' => true,
        'prompts' => [],
    ], $structuredOverrides);

    Http::fake(function ($request) use ($structured, $transcript, $engine, $draft) {
        if (str_contains($request->url(), '/api/v1/triage/stream')) {
            $draftText = $draft ?? (string) $structured['assistant_message'];
            $sse = implode("\n\n", [
                'data: '.json_encode(['hop' => 'Writing the reply', 'detail' => 'the current message']),
                'data: '.json_encode(['token' => $draftText]),
                'data: '.json_encode(['hop' => 'Checking urgency and summary']),
                'data: '.json_encode([
                    'done' => true,
                    'draft' => $draftText,
                    'structured' => $structured,
                ]),
            ])."\n\n";

            return Http::response($sse, 200, [
                'Content-Type' => 'text/event-stream',
            ]);
        }

        if (str_contains($request->url(), '/api/v1/triage')) {
            return Http::response([
                'draft' => $draft ?? (string) $structured['assistant_message'],
                'structured' => $structured,
            ], 200);
        }

        if (str_contains($request->url(), '/api/v1/transcribe')) {
            return Http::response([
                'transcript' => $transcript ?? 'I have chest pain',
                'engine' => $engine,
            ], 200);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            return fakeOpenAiChatCompletion($request);
        }

        if (str_contains($request->url(), 'audio/speech')) {
            return Http::response('fake-mp3', 200, [
                'Content-Type' => 'audio/mpeg',
            ]);
        }

        return Http::response(['error' => 'unexpected'], 500);
    });
}

test('patient can create a triage session and send multi-turn messages updating summary', function () {
    $triageResponses = [
        [
            'draft' => 'How long have you had these symptoms?',
            'structured' => [
                'assistant_message' => 'How long have you had these symptoms?',
                'urgency' => 'urgent',
                'chief_complaint' => 'chest pain',
                'summary' => 'Patient reports chest pain for two days.',
                'done' => false,
                'in_scope' => true,
            ],
        ],
        [
            'draft' => 'Any radiation to the arm?',
            'structured' => [
                'assistant_message' => 'Any radiation to the arm?',
                'urgency' => 'urgent',
                'chief_complaint' => 'chest pain',
                'summary' => 'Chest pain with fever; asking about radiation.',
                'done' => false,
                'in_scope' => true,
            ],
        ],
    ];
    $triageIndex = 0;

    Http::fake(function ($request) use (&$triageIndex, $triageResponses) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            $payload = $triageResponses[$triageIndex] ?? $triageResponses[array_key_last($triageResponses)];
            $triageIndex++;

            return Http::response($payload, 200);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            return fakeOpenAiChatCompletion($request);
        }

        if (str_contains($request->url(), 'audio/speech')) {
            return Http::response('fake-mp3', 200, [
                'Content-Type' => 'audio/mpeg',
            ]);
        }

        return Http::response(['error' => 'unexpected'], 500);
    });

    $patient = User::factory()->patient()->create();

    $create = $this->actingAs($patient)->postJson(route('voice.triage.sessions.store'));

    $create->assertCreated()
        ->assertJsonPath('session.status', 'active')
        ->assertJsonPath('session.subject_user_id', $patient->id)
        ->assertJsonPath('session.locale', '');

    $sessionId = $create->json('session.id');

    $turn1 = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $sessionId),
        ['text' => 'I have chest pain and mild fever for two days'],
    );

    $turn1->assertSuccessful()
        ->assertJsonPath('session.urgency', 'urgent')
        ->assertJsonPath('session.summary', 'Patient reports chest pain for two days.')
        ->assertJsonPath('session.locale', 'en')
        ->assertJsonPath('phases.thinking', true)
        ->assertJsonMissing(['suggested_followups']);

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
            && ($data['locale'] ?? null) === 'en'
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
        'locale' => 'en',
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
            && ($data['locale'] ?? null) === 'en'
            && in_array('User: User turn 3', $lines, true)
            && in_array('Assistant: Assistant turn 12', $lines, true)
            && ! in_array('User: User turn 1', $lines, true)
            && ! in_array('User: User turn 2', $lines, true)
            && count($lines) === 10
            && ! array_key_exists('messages', $data)
            && ! array_key_exists('history', $data);
    });
});

test('language detector sets triage locale and skips translate bridge', function () {
    $chatCalls = 0;
    Http::fake(function ($request) use (&$chatCalls) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            $data = $request->data();
            expect($data['user_message'] ?? null)->toBe('Saya demam tiga hari')
                ->and($data['locale'] ?? null)->toBe('ms')
                ->and($data['locale_name'] ?? null)->toBe('Bahasa Melayu');

            return Http::response([
                'draft' => 'Berapa tinggi demam anda?',
                'structured' => [
                    'assistant_message' => 'Berapa tinggi demam anda?',
                    'urgency' => 'urgent',
                    'chief_complaint' => 'fever',
                    'summary' => 'Patient reports fever for three days.',
                    'done' => false,
                    'in_scope' => true,
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            $chatCalls++;
            $system = (string) data_get($request->data(), 'messages.0.content', '');

            if (str_contains($system, 'classify messages for SihatAI Voice Triage')) {
                return Http::response([
                    'choices' => [['message' => ['content' => '{"intent":"triage","confidence":0.9,"reason":"fever"}']]],
                ], 200);
            }

            return Http::response([
                'choices' => [['message' => ['content' => '{"code":"ms","name":"Bahasa Melayu"}']]],
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
        'locale' => '',
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
        ->and($response->json('session.locale'))->toBe('ms')
        ->and($chatCalls)->toBe(2);

    $session->refresh();
    expect($session->locale)->toBe('ms')
        ->and($session->summary)->toBe('Patient reports fever for three days.');
});

test('off-topic intent gate skips medgemma and uses classifier redirect', function () {
    $triageCalls = 0;
    Http::fake(function ($request) use (&$triageCalls) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            $triageCalls++;

            return Http::response(['error' => 'should not call triage'], 500);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            $system = (string) data_get($request->data(), 'messages.0.content', '');

            if (str_contains($system, 'classify messages for SihatAI Voice Triage')) {
                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'intent' => 'off_topic',
                        'confidence' => 0.96,
                        'reason' => 'joke',
                        'redirect_message' => 'I can help with symptoms or care questions, but not stories. What health concern is on your mind?',
                    ])]]],
                ], 200);
            }

            return Http::response([
                'choices' => [['message' => ['content' => '{"code":"en","name":"English"}']]],
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
        'summary' => null,
        'chief_complaint' => null,
        'locale' => '',
    ]);

    $response = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'tell me some funny story'],
    );

    $response->assertSuccessful();
    $messages = $response->json('session.messages');

    expect($triageCalls)->toBe(0)
        ->and($messages)->toHaveCount(2)
        ->and($messages[1]['content'])->toBe(
            'I can help with symptoms or care questions, but not stories. What health concern is on your mind?',
        )
        ->and($response->json('session.summary'))->toBeNull();
});

test('short clinical follow-up still reaches medgemma despite borderline off_topic score', function () {
    $triageCalls = 0;
    $classifySawDialog = false;

    Http::fake(function ($request) use (&$triageCalls, &$classifySawDialog) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            $triageCalls++;

            return Http::response([
                'draft' => 'Rest, hydrate, and seek care if breathing worsens.',
                'structured' => [
                    'assistant_message' => 'Rest, hydrate, and seek care if breathing worsens.',
                    'urgency' => 'urgent',
                    'chief_complaint' => 'possible pneumonia follow-up',
                    'summary' => 'Patient asks what to do after possible pneumonia discussion.',
                    'done' => false,
                    'in_scope' => true,
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            $system = (string) data_get($request->data(), 'messages.0.content', '');
            $user = (string) data_get($request->data(), 'messages.1.content', '');

            if (str_contains($system, 'classify messages for SihatAI Voice Triage')) {
                $classifySawDialog = str_contains($user, 'Recent dialog:')
                    && str_contains($user, 'right lower lobe pneumonia')
                    && str_contains($user, 'Running clinical summary:');

                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'intent' => 'off_topic',
                        'confidence' => 0.8,
                        'reason' => 'vague follow-up without new symptoms',
                        'redirect_message' => 'Please share a health concern.',
                    ])]]],
                ], 200);
            }

            return Http::response([
                'choices' => [['message' => ['content' => '{"code":"zh","name":"Chinese"}']]],
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
        'summary' => 'Discussing prior chest x-ray with possible right lower lobe pneumonia.',
        'chief_complaint' => 'cough and fever',
        'locale' => 'zh',
    ]);

    TriageMessage::factory()->create([
        'triage_session_id' => $session->id,
        'role' => TriageMessageRole::User,
        'content' => '那你告诉我之前的验血报告怎么样啊',
    ]);
    TriageMessage::factory()->create([
        'triage_session_id' => $session->id,
        'role' => TriageMessageRole::Assistant,
        'content' => '结合你之前胸片提示右下肺可能有肺炎，建议线下就诊评估。',
    ]);

    $response = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => '那請問我怎麼辦'],
    );

    $response->assertSuccessful();

    expect($triageCalls)->toBe(1)
        ->and($classifySawDialog)->toBeTrue()
        ->and($response->json('session.messages.3.content'))->toBe(
            'Rest, hydrate, and seek care if breathing worsens.',
        );
});

test('unsafe intent gate skips medgemma and returns safety redirect', function () {
    $triageCalls = 0;
    Http::fake(function ($request) use (&$triageCalls) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            $triageCalls++;

            return Http::response(['error' => 'should not call triage'], 500);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            $system = (string) data_get($request->data(), 'messages.0.content', '');

            if (str_contains($system, 'classify messages for SihatAI Voice Triage')) {
                return Http::response([
                    'choices' => [['message' => ['content' => json_encode([
                        'intent' => 'unsafe',
                        'confidence' => 0.99,
                        'reason' => 'self-harm',
                        'redirect_message' => 'I cannot help with that. If you are in danger, contact local emergency or crisis support now.',
                    ])]]],
                ], 200);
            }

            return Http::response([
                'choices' => [['message' => ['content' => '{"code":"en","name":"English"}']]],
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
    ]);

    $response = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'how can I hurt myself'],
    );

    $response->assertSuccessful();

    expect($triageCalls)->toBe(0)
        ->and($response->json('session.messages.1.content'))->toContain('contact local emergency')
        ->and($response->json('session.summary'))->toBeNull();
});

test('structurer in_scope false does not adopt junk clinical summary', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            return Http::response([
                'draft' => 'Here is a funny joke about chickens.',
                'structured' => [
                    'assistant_message' => 'I am SihatAI Voice Triage. I can only help with symptoms, health questions, and care guidance. What health concern can I help with today?',
                    'urgency' => 'routine',
                    'chief_complaint' => 'joke request',
                    'summary' => 'Patient asked for a chicken joke.',
                    'done' => false,
                    'in_scope' => false,
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            return fakeOpenAiChatCompletion($request);
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
        'summary' => 'Prior clinical note',
        'chief_complaint' => 'cough',
    ]);

    $response = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'tell me a joke'],
    );

    $response->assertSuccessful()
        ->assertJsonPath('session.summary', 'Prior clinical note')
        ->assertJsonPath('session.chief_complaint', 'cough');

    expect($response->json('session.messages.1.content'))->toContain('SihatAI Voice Triage');

    $session->refresh();
    expect($session->summary)->toBe('Prior clinical note')
        ->and($session->chief_complaint)->toBe('cough');
});

function fakeTriageWebm(int $bytes = 2048): UploadedFile
{
    return UploadedFile::fake()->createWithContent('triage.webm', str_repeat('a', $bytes));
}

test('voice turn accepts audio asynchronously and omits stt language hint', function () {
    fakeTriageHttp(transcript: 'Saya sakit dada', engine: 'whisper');
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => 'en',
    ]);

    $response = $this->actingAs($patient)->post(
        route('voice.triage.sessions.messages', $session),
        ['audio' => fakeTriageWebm()],
        ['Accept' => 'application/json'],
    );

    $response->assertAccepted()
        ->assertJsonPath('status', 'processing');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/transcribe')) {
            return false;
        }
        $data = $request->data();

        return ! array_key_exists('language', $data)
            && isset($data['audio_b64'])
            && $data['audio_b64'] !== '';
    });

    expect($session->fresh()->messages)->toHaveCount(2);
});

test('text messages are rejected while a voice turn is processing', function () {
    Queue::fake();
    fakeTriageHttp();
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => '',
    ]);

    $this->actingAs($patient)->post(
        route('voice.triage.sessions.messages', $session),
        ['audio' => fakeTriageWebm()],
        ['Accept' => 'application/json'],
    )->assertAccepted();

    $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'I also have chest pain'],
    )->assertStatus(409);
});

test('voice turn dispatches queue job without blocking on fake queue', function () {
    Queue::fake();
    fakeTriageHttp();
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => '',
    ]);

    $response = $this->actingAs($patient)->post(
        route('voice.triage.sessions.messages', $session),
        ['audio' => fakeTriageWebm()],
        ['Accept' => 'application/json'],
    );

    $response->assertAccepted()
        ->assertJsonPath('status', 'processing')
        ->assertJsonPath('pending_turn.status', 'processing');

    Queue::assertPushed(ProcessTriageVoiceTurn::class, function (ProcessTriageVoiceTurn $job) use ($session, $patient) {
        return $job->sessionId === $session->id
            && $job->userId === $patient->id
            && $job->audioPath !== ''
            && $job->timeout >= 300
            && $job->failOnTimeout === true;
    });

    expect((int) config('queue.connections.database.retry_after'))
        ->toBeGreaterThan((new ProcessTriageVoiceTurn(1, 1, 'x'))->timeout);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/transcribe'));
});

test('voice turn rejects tiny audio before stt', function () {
    Queue::fake();
    fakeTriageHttp();
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => '',
    ]);

    $response = $this->actingAs($patient)->post(
        route('voice.triage.sessions.messages', $session),
        ['audio' => fakeTriageWebm(200)],
        ['Accept' => 'application/json'],
    );

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Hold the mic and speak before releasing.');

    Queue::assertNothingPushed();
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/v1/transcribe'));
});

test('voice turn marks pending turn failed for empty stt transcript', function () {
    fakeTriageHttp(transcript: '........');
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => '',
    ]);

    $response = $this->actingAs($patient)->post(
        route('voice.triage.sessions.messages', $session),
        ['audio' => fakeTriageWebm()],
        ['Accept' => 'application/json'],
    );

    $response->assertAccepted()
        ->assertJsonPath('pending_turn.status', 'failed')
        ->assertJsonPath(
            'pending_turn.message',
            'No speech heard. Speak closer to the mic and try again.',
        );

    expect($session->fresh()->messages)->toHaveCount(0);
});

test('voice turn reports warming up when stt is down', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/api/v1/transcribe')) {
            return Http::response(['detail' => 'cold start'], 502);
        }

        return fakeOpenAiChatCompletion($request);
    });

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => '',
        'status' => TriageSessionStatus::Active,
    ]);

    $response = $this->actingAs($patient)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post(route('voice.triage.sessions.messages', $session), [
            'audio' => fakeTriageWebm(),
        ]);

    $response->assertSuccessful();
    expect($response->streamedContent())->toContain(
        'Speech service is warming up. Type your message or try again in a moment.',
    );
    expect($session->fresh()->messages)->toHaveCount(0);
});

test('stale pending voice turn is marked failed', function () {
    $session = TriageSession::factory()->create();

    Cache::put('triage.pending.'.$session->id, [
        'status' => 'processing',
        'started_at' => now()->subSeconds(TriageTurnStatus::STALE_AFTER_SECONDS + 1)->getTimestamp(),
    ], now()->addMinutes(15));

    expect(TriageTurnStatus::get($session->id))->toMatchArray([
        'status' => 'failed',
        'message' => 'Voice processing timed out. Please try again.',
    ]);
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
            ->where('sharedSessions.0.id', $session->id)
            ->missing('languages'));
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
        'locale' => 'en',
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

    $create = $this->actingAs($physician)->postJson(route('voice.triage.sessions.store'));

    $create->assertCreated()
        ->assertJsonPath('session.subject_user_id', null)
        ->assertJsonPath('session.role_context', 'physician')
        ->assertJsonPath('session.locale', '');

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
        'locale' => 'en',
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

    $response = $this->actingAs($patient)
        ->get(route('voice.triage.sessions.messages.speak', [
            'session' => $session,
            'message' => $assistant,
        ]), [
            'Accept' => 'audio/mpeg',
        ]);

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'audio/mpeg');

    expect($response->streamedContent())->toBe('replay-mp3');

    $this->actingAs($patient)
        ->get(route('voice.triage.sessions.messages.speak', [
            'session' => $session,
            'message' => $userMessage,
        ]))
        ->assertUnprocessable();
});

test('triage message stream yields hops tokens and persists the reply', function () {
    fakeTriageHttp();

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => '',
        'status' => TriageSessionStatus::Active,
    ]);

    $response = $this->actingAs($patient)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post(route('voice.triage.sessions.messages', $session), [
            'text' => 'I have a fever',
        ]);

    $response->assertSuccessful();

    $body = $response->streamedContent();

    expect($body)->toContain('"event":"hop"')
        ->and($body)->toContain('Reading your message')
        ->and($body)->toContain('Asking MedGemma')
        ->and($body)->toContain('Writing the reply')
        ->and($body)->toContain('"event":"token"')
        ->and($body)->toContain('How long have you had these symptoms?')
        ->and($body)->toContain('"event":"assistant"')
        ->and($body)->toContain('"event":"session"');

    expect($session->fresh()->messages)->toHaveCount(2)
        ->and($session->fresh()->messages->last()->content)->toBe('How long have you had these symptoms?');
});

test('prompt-echo draft is not streamed or persisted', function (string $leak) {
    $reply = 'For a slight fever, paracetamol 500 mg every 4 to 6 hours is a usual first option.';

    fakeTriageHttp([
        'assistant_message' => $reply,
        'chief_complaint' => 'slight fever',
        'summary' => 'Adult with a slight fever asking about medication.',
        'urgency' => 'routine',
    ], draft: $leak);

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => 'en',
        'status' => TriageSessionStatus::Active,
    ]);

    $response = $this->actingAs($patient)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post(route('voice.triage.sessions.messages', $session), [
            'text' => "Who has the fever?: Adult\nWhat is the highest measured temperature?: 38-39C",
        ]);

    $response->assertSuccessful();

    $body = $response->streamedContent();

    expect($body)->not->toContain('Write the next triage reply')
        ->and($body)->not->toContain('Current user message:')
        ->and($body)->not->toContain('prefer this for immediate continuity')
        ->and($body)->toContain($reply)
        ->and($session->fresh()->messages->last()->content)->toBe($reply);
})->with([
    'instruction tail' => 'Write the next triage reply in English. Stay in medical triage scope. Plain text only, not JSON.',
    'dialog body' => "Recent dialog (up to last 10 messages; prefer this for immediate continuity):\nCurrent user message:\nWhat is your highest measured temperature?: Under 38C",
]);

test('leaked assistant history is not sent back to medgemma', function () {
    $captured = [];

    Http::fake(function ($request) use (&$captured) {
        if (str_contains($request->url(), '/api/v1/triage')) {
            $captured[] = $request->data();

            return Http::response([
                'draft' => 'For a slight fever, rest and paracetamol are usual first steps.',
                'structured' => [
                    'assistant_message' => 'For a slight fever, rest and paracetamol are usual first steps.',
                    'urgency' => 'routine',
                    'chief_complaint' => 'slight fever',
                    'summary' => 'Adult with a slight fever.',
                    'done' => false,
                    'in_scope' => true,
                    'prompts' => [],
                ],
            ], 200);
        }

        if (str_contains($request->url(), 'chat/completions')) {
            return fakeOpenAiChatCompletion($request);
        }

        if (str_contains($request->url(), 'audio/speech')) {
            return Http::response('fake-mp3', 200, [
                'Content-Type' => 'audio/mpeg',
            ]);
        }

        return Http::response(['error' => 'unexpected'], 500);
    });

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => 'en',
        'status' => TriageSessionStatus::Active,
    ]);

    TriageMessage::factory()->create([
        'triage_session_id' => $session->id,
        'role' => TriageMessageRole::User,
        'content' => 'i would like to know what medication should i take when i have a slight fever',
    ]);
    TriageMessage::factory()->create([
        'triage_session_id' => $session->id,
        'role' => TriageMessageRole::Assistant,
        'content' => 'Write the next triage reply in English. Plain text only, not JSON.',
    ]);

    $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'Who has the fever?: Adult'],
    )->assertSuccessful();

    $dialog = (string) data_get($captured, '0.recent_dialog', '');

    expect($captured)->not->toBeEmpty()
        ->and($dialog)->toContain('slight fever')
        ->and($dialog)->not->toContain('Write the next triage reply');
});

test('triage turn persists follow-up prompts for the selection card', function () {
    fakeTriageHttp([
        'prompts' => [
            [
                'id' => 'fever',
                'question' => 'Any fever with this cough?',
                'allow_multiple' => false,
                'options' => [
                    ['id' => 'yes', 'label' => 'Yes'],
                    ['id' => 'no', 'label' => 'No'],
                    ['id' => 'unsure', 'label' => 'Not sure'],
                ],
            ],
            [
                'id' => 'duration',
                'question' => 'How long have you had this?',
                'allow_multiple' => false,
                'options' => [
                    ['id' => 'lt2d', 'label' => 'Less than 2 days'],
                    ['id' => '2to7d', 'label' => '2 to 7 days'],
                    ['id' => 'gt7d', 'label' => 'More than a week'],
                ],
            ],
            ['question' => 'no'],
        ],
    ]);

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
    ]);

    $turn = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'I have a cough'],
    );

    $turn->assertSuccessful()
        ->assertJsonPath('session.messages.1.prompts.0.id', 'fever')
        ->assertJsonPath('session.messages.1.prompts.1.id', 'duration');

    expect($turn->json('session.messages.1.prompts'))->toHaveCount(2)
        ->and($session->fresh()->messages->last()->prompts)->toHaveCount(2);
});

test('card answers do not open another prompt card', function () {
    fakeTriageHttp([
        'assistant_message' => 'For a slight fever with cough, rest and paracetamol are usual first steps.',
        'chief_complaint' => 'slight fever',
        'summary' => 'Adult with a slight fever and cough.',
        'urgency' => 'routine',
        'done' => false,
        'prompts' => [
            [
                'id' => 'meds',
                'question' => 'Have you taken anything for this?',
                'allow_multiple' => false,
                'options' => [
                    ['id' => 'yes', 'label' => 'Yes'],
                    ['id' => 'no', 'label' => 'No'],
                ],
            ],
        ],
    ]);

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
    ]);

    $turn = $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => "Which symptoms do you have now?: Cough\nDo you have a condition that raises the risk?: No"],
    );

    $turn->assertSuccessful();

    expect($turn->json('session.messages.1.prompts'))->toBe([])
        ->and($session->fresh()->messages->last()->prompts)->toBeNull();
});

test('ai disclaimer sentences are stripped from the persisted reply', function () {
    $hedged = 'Rest and paracetamol 500 mg every 4 to 6 hours are usual first steps. I am a large language model, not a medical professional. I cannot provide medical advice. Please consult a licensed clinician for diagnosis and treatment.';

    fakeTriageHttp([
        'assistant_message' => $hedged,
        'chief_complaint' => 'slight fever',
        'summary' => 'Adult with a slight fever.',
        'urgency' => 'routine',
        'prompts' => [],
    ], draft: $hedged);

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
    ]);

    $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'I have a slight fever'],
    )->assertSuccessful();

    $content = (string) $session->fresh()->messages->last()->content;

    expect($content)->toContain('paracetamol')
        ->and($content)->not->toContain('large language model')
        ->and($content)->not->toContain('medical professional')
        ->and($content)->not->toContain('licensed clinician');
});

test('a see-a-doctor stub is replaced by the structured medication plan', function () {
    $stub = 'This is a low-grade fever. If you have a cough, sore throat, or shortness of breath, it is best to monitor your symptoms and contact a healthcare provider for further evaluation.';
    $plan = 'This is often a viral upper respiratory infection. Paracetamol 500 mg to 1 g every 4 to 6 hours (maximum 4 g in 24 hours) is the usual first option. Rest, fluids, and throat lozenges help. If shortness of breath is new or worsening, go to the emergency department now.';

    fakeTriageHttp([
        'assistant_message' => $plan,
        'chief_complaint' => 'fever and cough',
        'summary' => 'Low-grade fever starting today with cough and sore throat.',
        'urgency' => 'urgent',
        'prompts' => [],
    ], draft: $stub);

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
    ]);

    $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => "What is the highest temperature you have measured?: Under 38°C\nWhen did the fever start?: Today"],
    )->assertSuccessful();

    $content = (string) $session->fresh()->messages->last()->content;

    expect($content)->toContain('Paracetamol')
        ->and($content)->not->toContain('healthcare provider')
        ->and($content)->not->toContain('further evaluation');
});

test('the structured plan is shown even when the medgemma draft is fluent', function () {
    $draft = 'This is a low-grade fever starting today.';
    $plan = 'This is often viral. Paracetamol 500 mg to 1 g every 4 to 6 hours is the usual first option. Rest and fluids. Go to the emergency department now if breathing becomes hard.';

    fakeTriageHttp([
        'assistant_message' => $plan,
        'chief_complaint' => 'fever',
        'summary' => 'Low-grade fever starting today.',
        'urgency' => 'routine',
        'prompts' => [],
    ], draft: $draft);

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
    ]);

    $this->actingAs($patient)->postJson(
        route('voice.triage.sessions.messages', $session),
        ['text' => 'I have a fever since this morning'],
    )->assertSuccessful();

    expect($session->fresh()->messages->last()->content)->toBe($plan);
});

test('sse audio message skips the voice job and transcribes inline', function () {
    Queue::fake();
    fakeTriageHttp(transcript: 'I have chest pain');

    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'locale' => '',
        'status' => TriageSessionStatus::Active,
    ]);

    $response = $this->actingAs($patient)
        ->withHeaders(['Accept' => 'text/event-stream'])
        ->post(route('voice.triage.sessions.messages', $session), [
            'audio' => fakeTriageWebm(),
        ]);

    $response->assertSuccessful();
    Queue::assertNothingPushed();

    $body = $response->streamedContent();

    expect($body)->toContain('Transcribing speech')
        ->and($body)->toContain('Heard you')
        ->and($body)->toContain('I have chest pain');

    expect($session->fresh()->messages)->toHaveCount(2)
        ->and($session->fresh()->messages->first()->content)->toBe('I have chest pain');
});
