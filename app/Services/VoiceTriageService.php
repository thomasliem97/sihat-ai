<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Enums\TriageInputModality;
use App\Enums\TriageMessageRole;
use App\Enums\TriageRoleContext;
use App\Enums\TriageSessionStatus;
use App\Enums\TriageUrgency;
use App\Models\MedicalRecord;
use App\Models\TriageMessage;
use App\Models\TriageSession;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceTriageService
{
    private const INTENT_TRIAGE = 'triage';

    private const INTENT_OFF_TOPIC = 'off_topic';

    private const INTENT_UNSAFE = 'unsafe';

    /**
     * @return array{message: TriageMessage, session: TriageSession, audio_base64: string|null, phases: array<string, bool>, done: bool}
     */
    public function sendMessage(
        TriageSession $session,
        User $actor,
        ?string $text = null,
        ?UploadedFile $audio = null,
        bool $withSpeech = true,
    ): array {
        $phases = [
            'transcribing' => false,
            'thinking' => false,
            'speaking' => false,
        ];

        $sttEngine = null;
        $content = trim((string) $text);
        $modality = TriageInputModality::Text;

        if ($audio !== null) {
            $phases['transcribing'] = true;
            if ($audio->getSize() < 1500) {
                throw new \InvalidArgumentException('Hold the mic and speak before releasing.');
            }
            $stt = $this->speechToText($audio);
            $sttEngine = is_string($stt['engine'] ?? null) ? $stt['engine'] : null;
            $transcript = trim($stt['transcript']);
            if ($transcript === '' || ! preg_match('/\p{L}|\p{N}/u', $transcript)) {
                throw new \InvalidArgumentException('No speech heard. Speak closer to the mic and try again.');
            }
            $content = $transcript;
            $modality = TriageInputModality::Voice;
        }

        if ($content === '') {
            throw new \InvalidArgumentException('Provide text or audio for this triage turn.');
        }

        $userMessage = $session->messages()->create([
            'role' => TriageMessageRole::User,
            'content' => $content,
            'input_modality' => $modality,
            'stt_engine' => $sttEngine,
        ]);

        $phases['thinking'] = true;
        $detected = $this->detectLanguage($content);
        $session->locale = $detected['code'];
        $session->save();

        $intent = $this->classifyIntent(
            $session,
            $userMessage,
            $content,
            $detected['code'],
            $detected['name'],
        );
        $minConfidence = (float) config('services.triage.intent_confidence_min', 0.85);

        if (
            in_array($intent['intent'], [self::INTENT_OFF_TOPIC, self::INTENT_UNSAFE], true)
            && $intent['confidence'] >= $minConfidence
        ) {
            return $this->finishRefusedTurn(
                $session,
                $userMessage,
                $phases,
                $intent['intent'],
                $detected['code'],
                $withSpeech,
                $intent['redirect_message'],
            );
        }

        $triage = $this->runTriageTurn(
            $session,
            $actor,
            $content,
            $userMessage,
            $detected['code'],
            $detected['name'],
        );
        $structured = is_array($triage['structured'] ?? null) ? $triage['structured'] : [];
        $inScope = array_key_exists('in_scope', $structured)
            ? (bool) $structured['in_scope']
            : true;

        if (! $inScope) {
            $assistantFromModel = trim((string) ($structured['assistant_message'] ?? ''));
            $draftRedirect = trim((string) ($triage['draft'] ?? ''));
            $assistantMessageText = $assistantFromModel !== ''
                ? $assistantFromModel
                : ($draftRedirect !== ''
                    ? $draftRedirect
                    : $this->scopeRedirectMessage(self::INTENT_OFF_TOPIC, $detected['code']));

            $assistantMessage = $session->messages()->create([
                'role' => TriageMessageRole::Assistant,
                'content' => $assistantMessageText,
                'input_modality' => TriageInputModality::Text,
                'stt_engine' => null,
            ]);

            $phases['speaking'] = true;

            return [
                'message' => $assistantMessage,
                'user_message' => $userMessage,
                'session' => $session->fresh(['messages', 'subjectUser:id,name']),
                'audio_base64' => $withSpeech ? $this->textToSpeech($assistantMessageText) : null,
                'phases' => $phases,
                'done' => false,
            ];
        }

        $assistantMessageText = trim((string) ($structured['assistant_message'] ?? ''));
        if ($assistantMessageText === '') {
            $assistantMessageText = trim((string) ($triage['draft'] ?? ''))
                ?: 'I heard you. Can you tell me a bit more about your main symptom?';
        }

        $urgency = TriageUrgency::tryFrom((string) ($structured['urgency'] ?? ''))
            ?? TriageUrgency::Routine;

        $session->fill([
            'urgency' => $urgency,
            'chief_complaint' => $this->nullableString($structured['chief_complaint'] ?? null) ?? $session->chief_complaint,
            'summary' => $this->nullableString($structured['summary'] ?? null) ?? $session->summary,
        ]);
        $session->save();

        $assistantMessage = $session->messages()->create([
            'role' => TriageMessageRole::Assistant,
            'content' => $assistantMessageText,
            'input_modality' => TriageInputModality::Text,
            'stt_engine' => null,
        ]);

        $phases['speaking'] = true;

        return [
            'message' => $assistantMessage,
            'user_message' => $userMessage,
            'session' => $session->fresh(['messages', 'subjectUser:id,name']),
            'audio_base64' => $withSpeech ? $this->textToSpeech($assistantMessageText) : null,
            'phases' => $phases,
            'done' => (bool) ($structured['done'] ?? false),
        ];
    }

    public function speakMessage(TriageMessage $message): ?string
    {
        if ($message->role !== TriageMessageRole::Assistant) {
            throw new \InvalidArgumentException('Only assistant messages can be spoken.');
        }

        return $this->textToSpeech($message->content);
    }

    public function archive(TriageSession $session): TriageSession
    {
        if ($session->status === TriageSessionStatus::Archived) {
            return $session->fresh(['messages', 'subjectUser:id,name']) ?? $session;
        }

        $session->status = TriageSessionStatus::Archived;
        $session->save();

        return $session->fresh(['messages', 'subjectUser:id,name']);
    }

    public function share(TriageSession $session): TriageSession
    {
        $session->shared_at = Carbon::now();
        $session->save();

        return $session->fresh(['messages', 'subjectUser:id,name']);
    }

    /**
     * @param  array<string, bool>  $phases
     * @return array{message: TriageMessage, session: TriageSession, audio_base64: string|null, phases: array<string, bool>, done: bool}
     */
    private function finishRefusedTurn(
        TriageSession $session,
        TriageMessage $userMessage,
        array $phases,
        string $intent,
        string $localeCode,
        bool $withSpeech = true,
        ?string $redirectMessage = null,
    ): array {
        $fromClassifier = trim((string) $redirectMessage);
        $assistantMessageText = $fromClassifier !== ''
            ? $fromClassifier
            : $this->scopeRedirectMessage($intent, $localeCode);

        $assistantMessage = $session->messages()->create([
            'role' => TriageMessageRole::Assistant,
            'content' => $assistantMessageText,
            'input_modality' => TriageInputModality::Text,
            'stt_engine' => null,
        ]);

        $phases['speaking'] = true;

        return [
            'message' => $assistantMessage,
            'user_message' => $userMessage,
            'session' => $session->fresh(['messages', 'subjectUser:id,name']),
            'audio_base64' => $withSpeech ? $this->textToSpeech($assistantMessageText) : null,
            'phases' => $phases,
            'done' => false,
        ];
    }

    /**
     * @return array{intent: string, confidence: float, reason: string, redirect_message: string}
     */
    private function classifyIntent(
        TriageSession $session,
        TriageMessage $currentUserMessage,
        string $text,
        string $localeCode = 'en',
        string $localeName = 'English',
    ): array {
        $fallback = [
            'intent' => self::INTENT_TRIAGE,
            'confidence' => 0.0,
            'reason' => 'fallback_allow',
            'redirect_message' => '',
        ];
        $apiKey = config('services.openai.api_key');
        if (! $apiKey || trim($text) === '') {
            return $fallback;
        }

        $model = (string) (config('services.triage.intent_model')
            ?: config('services.triage.detect_model')
            ?: 'gpt-4o-mini');
        $replyLang = trim($localeName) !== '' ? trim($localeName) : (trim($localeCode) !== '' ? $localeCode : 'English');
        $recentDialog = $this->buildRecentDialog($session, $currentUserMessage, limit: 6);
        $summary = trim((string) ($session->summary ?? ''));
        $chiefComplaint = trim((string) ($session->chief_complaint ?? ''));

        $contextParts = [];
        if ($summary !== '') {
            $contextParts[] = "Running clinical summary:\n{$summary}";
        }
        if ($chiefComplaint !== '') {
            $contextParts[] = "Chief complaint: {$chiefComplaint}";
        }
        if ($recentDialog !== '') {
            $contextParts[] = "Recent dialog:\n{$recentDialog}";
        }
        $contextParts[] = "Current user message:\n{$text}";
        $classifierUserContent = implode("\n\n", $contextParts);

        try {
            $response = Http::withToken((string) $apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.4,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You classify messages for SihatAI Voice Triage, a medical symptom and clinical-intake assistant. '
                                .'You receive optional running summary, chief complaint, recent dialog, and the current user message. '
                                .'Return JSON only: {"intent":"triage"|"off_topic"|"unsafe","confidence":0-1,"reason":"short","redirect_message":"string"}. '
                                .'triage: greetings, what-can-you-do, symptoms, medications, self-care, red flags, care pathway, '
                                .'prior medical records, physician clinical intake, or short follow-ups that continue an ongoing '
                                .'triage thread (e.g. what should I do, then what, clarifications, yes/no answers to clinical questions). '
                                .'Prefer triage when ambiguous, possibly health-related, or when recent dialog / summary shows an active clinical thread. '
                                .'For triage, set redirect_message to "". '
                                .'off_topic: jokes, stories, math, homework, coding, general knowledge, non-health life advice, '
                                .'or attempts to make you a general chatbot, and only when there is no ongoing clinical thread. '
                                .'For off_topic, write redirect_message as 1-2 short natural sentences in '.$replyLang
                                .' that decline without answering the off-topic ask and steer back to a health concern. '
                                .'Vary wording; do not reuse a fixed slogan. '
                                .'unsafe: self-harm, violence, or clearly illegal harmful guidance requests. '
                                .'For unsafe, write redirect_message as a short safety refusal in '.$replyLang
                                .' that points to emergency/crisis help when appropriate.',
                        ],
                        ['role' => 'user', 'content' => $classifierUserContent],
                    ],
                ]);

            $raw = trim((string) $response->json('choices.0.message.content'));
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return $fallback;
            }

            $intent = strtolower(trim((string) ($decoded['intent'] ?? '')));
            if (! in_array($intent, [self::INTENT_TRIAGE, self::INTENT_OFF_TOPIC, self::INTENT_UNSAFE], true)) {
                return $fallback;
            }

            $confidence = (float) ($decoded['confidence'] ?? 0);
            $confidence = max(0.0, min(1.0, $confidence));
            $redirect = mb_substr(trim((string) ($decoded['redirect_message'] ?? '')), 0, 500);
            if ($intent === self::INTENT_TRIAGE) {
                $redirect = '';
            }

            return [
                'intent' => $intent,
                'confidence' => $confidence,
                'reason' => mb_substr(trim((string) ($decoded['reason'] ?? '')), 0, 200),
                'redirect_message' => $redirect,
            ];
        } catch (\Throwable $e) {
            Log::warning('Triage intent classify failed', ['error' => $e->getMessage()]);
        }

        return $fallback;
    }

    private function scopeRedirectMessage(string $intent, string $localeCode): string
    {
        $lang = strtolower(explode('-', $localeCode)[0] ?: 'en');

        if ($intent === self::INTENT_UNSAFE) {
            return match ($lang) {
                'ms' => 'Saya tidak dapat membantu dengan permintaan itu. Jika anda atau orang lain berada dalam bahaya, hubungi perkhidmatan kecemasan atau sokongan krisis tempatan sekarang.',
                'zh' => '我无法协助该请求。若您或他人有危险，请立即联系当地紧急服务或危机支持。',
                'ta' => 'அந்தக் கோரிக்கைக்கு நான் உதவ முடியாது. நீங்கள் அல்லது வேறு யாராவது ஆபத்தில் இருந்தால், உள்ளூர் அவசர சேவை அல்லது நெருக்கடி உதவியை உடனே தொடர்பு கொள்ளுங்கள்.',
                default => 'I cannot help with that request. If you or someone else is in danger, contact local emergency services or crisis support now.',
            };
        }

        return match ($lang) {
            'ms' => 'Saya SihatAI Voice Triage. Saya hanya boleh membantu dengan simptom, soalan kesihatan, dan panduan penjagaan. Apakah kebimbangan kesihatan yang boleh saya bantu hari ini?',
            'zh' => '我是 SihatAI 语音分诊。我只能协助症状、健康问题与就医建议。今天有什么健康问题需要帮忙？',
            'ta' => 'நான் SihatAI Voice Triage. அறிகுறிகள், சுகாதார கேள்விகள் மற்றும் பராமரிப்பு வழிகாட்டுதலுக்கு மட்டுமே உதவ முடியும். இன்று எந்த சுகாதார கவலையில் உதவலாம்?',
            default => 'I am SihatAI Voice Triage. I can only help with symptoms, health questions, and care guidance. What health concern can I help with today?',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function runTriageTurn(
        TriageSession $session,
        User $actor,
        string $userMessage,
        TriageMessage $currentUserMessage,
        string $locale,
        string $localeName,
    ): array {
        $session->loadMissing('subjectUser');
        $baseUrl = rtrim((string) config('services.modal.url'), '/');
        $payload = [
            'role_context' => $session->role_context->value,
            'summary' => (string) ($session->summary ?? ''),
            'recent_dialog' => $this->buildRecentDialog($session, $currentUserMessage),
            'record_context' => $this->buildRecordContext($session),
            'user_message' => $userMessage,
            'locale' => $locale,
            'locale_name' => $localeName,
            'subject_name' => $session->subject_user_id !== null
                ? $session->subjectUser->name
                : ($session->role_context === TriageRoleContext::Patient ? $actor->name : ''),
        ];

        try {
            $response = Http::timeout(180)->post("{$baseUrl}/api/v1/triage", $payload);
            if ($response->successful()) {
                /** @var array<string, mixed> $json */
                $json = $response->json();

                return $json;
            }

            Log::warning('Triage Modal call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Triage Modal call exception', ['error' => $e->getMessage()]);
        }

        return $this->fallbackTriage($userMessage, (string) ($session->summary ?? ''));
    }

    /**
     * Prior messages for short-horizon continuity (session language as spoken).
     */
    private function buildRecentDialog(
        TriageSession $session,
        TriageMessage $currentUserMessage,
        int $limit = 10,
    ): string {
        $prior = $session->messages()
            ->where('id', '<', $currentUserMessage->id)
            ->reorder()
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        if ($prior->isEmpty()) {
            return '';
        }

        return $prior->map(function (TriageMessage $message): string {
            $label = $message->role === TriageMessageRole::Assistant ? 'Assistant' : 'User';

            return "{$label}: {$message->content}";
        })->implode("\n");
    }

    /**
     * @return array{transcript: string, engine: string|null}
     */
    private function speechToText(UploadedFile $audio): array
    {
        $baseUrl = rtrim((string) config('services.modal.url'), '/');
        $bytes = file_get_contents($audio->getRealPath()) ?: '';

        // No language hint: Modal Whisper auto-detects, then MedASR if English.
        $payload = [
            'audio_b64' => base64_encode($bytes),
        ];

        try {
            $response = Http::timeout(120)->post("{$baseUrl}/api/v1/transcribe", $payload);

            if ($response->successful()) {
                return [
                    'transcript' => (string) ($response->json('transcript') ?? ''),
                    'engine' => $response->json('engine'),
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('Triage STT failed', ['error' => $e->getMessage()]);
        }

        return ['transcript' => '', 'engine' => 'unavailable'];
    }

    /**
     * @return array{code: string, name: string}
     */
    private function detectLanguage(string $text): array
    {
        $fallback = ['code' => 'en', 'name' => 'English'];
        $apiKey = config('services.openai.api_key');
        if (! $apiKey || trim($text) === '') {
            return $fallback;
        }

        $model = (string) (config('services.triage.detect_model') ?: 'gpt-4o-mini');

        try {
            $response = Http::withToken((string) $apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Detect the primary language of the user message for a medical triage chat. '
                                .'Return JSON only: {"code":"<BCP-47 or ISO 639-1 like en, ms, zh, ta, ja>","name":"<English language name>"}. '
                                .'Use the dominant language if mixed. Do not translate or answer the message.',
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            $raw = trim((string) $response->json('choices.0.message.content'));
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                return $fallback;
            }

            $code = strtolower(trim((string) ($decoded['code'] ?? '')));
            $name = trim((string) ($decoded['name'] ?? ''));
            if ($code === '' || $name === '') {
                return $fallback;
            }

            $code = preg_replace('/[^a-z0-9-]/', '', $code) ?: 'en';
            $code = mb_substr($code, 0, 16);

            return [
                'code' => $code,
                'name' => mb_substr($name, 0, 64),
            ];
        } catch (\Throwable $e) {
            Log::warning('Triage language detect failed', ['error' => $e->getMessage()]);
        }

        return $fallback;
    }

    private function textToSpeech(string $text): ?string
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey || trim($text) === '') {
            return null;
        }

        $model = (string) config('services.triage.tts_model', 'gpt-4o-mini-tts');
        $voice = (string) config('services.triage.tts_voice', 'marin');
        $speed = (float) config('services.triage.tts_speed', 1.5);
        $speed = max(0.25, min(4.0, $speed));
        $instructions = (string) config(
            'services.triage.tts_instructions',
            'Voice identity: A warm, polished female clinical professional speaking face to face with a patient. Affect: Calm, composed, reassuring, and gently upbeat, with quiet confidence. Tone: Human, sincere, empathetic, and conversational; sound attentive rather than scripted. Pacing: Natural and moderate, never rushed. Use subtle changes in rhythm and intonation, with brief pauses after questions, important guidance, and reassuring statements. Emotion: Let a pleasant warmth show in greetings and routine guidance, but become appropriately serious and grounded for distressing symptoms, urgent advice, or safety warnings. Pronunciation: Clear and precise without over-enunciating; emphasize medication names, doses, timeframes, warning signs, and next steps when present. Delivery: Speak fluidly as one clinician to one patient. Avoid a flat monotone, sing-song cadence, exaggerated cheerfulness, announcer voice, phone-menu rhythm, or chatbot-like delivery.',
        );

        try {
            $response = Http::withToken((string) $apiKey)
                ->timeout(60)
                ->withHeaders(['Accept' => 'audio/mpeg'])
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => $model,
                    'voice' => $voice,
                    'input' => mb_substr($text, 0, 2000),
                    'speed' => $speed,
                    'instructions' => $instructions,
                ]);

            if ($response->successful()) {
                return base64_encode($response->body());
            }
        } catch (\Throwable $e) {
            Log::warning('Triage TTS failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function buildRecordContext(TriageSession $session): string
    {
        $subjectId = $session->subject_user_id;
        if ($subjectId === null && $session->role_context === TriageRoleContext::Patient) {
            $subjectId = $session->user_id;
        }

        if ($subjectId === null) {
            return '';
        }

        $records = MedicalRecord::query()
            ->where('status', RecordStatus::Completed)
            ->where(function ($q) use ($subjectId) {
                $q->where('subject_user_id', $subjectId)
                    ->orWhere(function ($inner) use ($subjectId) {
                        $inner->whereNull('subject_user_id')->where('user_id', $subjectId);
                    });
            })
            ->latest('analyzed_at')
            ->limit(5)
            ->get(['id', 'title', 'modality', 'detected_modality', 'findings', 'physician_report', 'analyzed_at', 'created_at']);

        if ($records->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($records as $record) {
            $modality = ($record->detected_modality ?? $record->modality)->label();
            $date = ($record->analyzed_at ?? $record->created_at)->toDateString();
            $summary = '';
            if (is_array($record->physician_report) && isset($record->physician_report['summary'])) {
                $summary = trim((string) $record->physician_report['summary']);
            }
            if ($summary === '' && is_array($record->findings)) {
                $labels = collect(array_values($record->findings))->pluck('label')->filter()->take(5)->implode(', ');
                $summary = $labels !== '' ? "Findings: {$labels}" : '';
            }
            $lines[] = "- {$date} · {$modality} · {$record->title}"
                .($summary !== '' ? " · {$summary}" : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{draft: string, structured: array<string, mixed>}
     */
    private function fallbackTriage(string $userMessage, string $summary): array
    {
        $lower = mb_strtolower($userMessage);
        $urgency = 'routine';
        if (str_contains($lower, 'chest pain') || str_contains($lower, 'shortness of breath') || str_contains($lower, 'fever')) {
            $urgency = 'urgent';
        }
        if (str_contains($lower, 'unconscious') || str_contains($lower, 'not breathing') || str_contains($lower, 'severe bleeding')) {
            $urgency = 'emergency';
        }

        $assistant = match ($urgency) {
            'emergency' => 'This may be an emergency. Please seek emergency care now or call local emergency services.',
            'urgent' => 'Thanks for sharing. How long have you had these symptoms, and are they getting worse?',
            default => 'Thanks for sharing. What is the main symptom bothering you most today?',
        };

        $newSummary = trim($summary."\nUser: {$userMessage}\nAssistant: {$assistant}");

        return [
            'draft' => $assistant,
            'structured' => [
                'assistant_message' => $assistant,
                'urgency' => $urgency,
                'chief_complaint' => mb_substr($userMessage, 0, 120),
                'summary' => mb_substr($newSummary, 0, 2000),
                'done' => false,
                'in_scope' => true,
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
