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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceTriageService
{
    /**
     * @return array{message: TriageMessage, session: TriageSession, audio_base64: string|null, phases: array<string, bool>}
     */
    public function sendMessage(
        TriageSession $session,
        User $actor,
        ?string $text = null,
        ?UploadedFile $audio = null,
    ): array {
        $locale = $session->locale->value;
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
            $stt = $this->speechToText($audio, $locale);
            $sttEngine = is_string($stt['engine'] ?? null) ? $stt['engine'] : null;
            $transcript = trim((string) ($stt['transcript'] ?? ''));
            if ($transcript === '') {
                throw new \InvalidArgumentException('Could not understand the audio. Please try again.');
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
        $userMessageEn = $locale === 'en'
            ? $content
            : $this->translate($content, $locale, 'en');

        $triage = $this->runTriageTurn($session, $actor, $userMessageEn, $userMessage);
        $structured = is_array($triage['structured'] ?? null) ? $triage['structured'] : [];

        $assistantEn = trim((string) ($structured['assistant_message'] ?? ''));
        if ($assistantEn === '') {
            $assistantEn = trim((string) ($triage['draft'] ?? '')) ?: 'I heard you. Can you tell me a bit more about your main symptom?';
        }

        $assistantLocale = $locale === 'en'
            ? $assistantEn
            : $this->translate($assistantEn, 'en', $locale);

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
            'content' => $assistantLocale,
            'input_modality' => TriageInputModality::Text,
            'stt_engine' => null,
        ]);

        $phases['speaking'] = true;
        $audioBase64 = $this->textToSpeech($assistantLocale);

        return [
            'message' => $assistantMessage,
            'user_message' => $userMessage,
            'session' => $session->fresh(['messages', 'subjectUser:id,name']),
            'audio_base64' => $audioBase64,
            'phases' => $phases,
            'suggested_followups' => array_values(array_filter(
                array_map('strval', is_array($structured['suggested_followups'] ?? null) ? $structured['suggested_followups'] : [])
            )),
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
        $session->shared_at = now();
        $session->save();

        return $session->fresh(['messages', 'subjectUser:id,name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runTriageTurn(
        TriageSession $session,
        User $actor,
        string $userMessageEn,
        TriageMessage $currentUserMessage,
    ): array {
        $session->loadMissing('subjectUser');
        $baseUrl = rtrim((string) config('services.modal.url'), '/');
        $payload = [
            'role_context' => $session->role_context->value,
            'summary' => (string) ($session->summary ?? ''),
            'recent_dialog' => $this->buildRecentDialog($session, $currentUserMessage),
            'record_context' => $this->buildRecordContext($session),
            'user_message' => $userMessageEn,
            'subject_name' => $session->subjectUser?->name
                ?? ($session->role_context === TriageRoleContext::Patient ? $actor->name : ''),
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

        return $this->fallbackTriage($userMessageEn, (string) ($session->summary ?? ''));
    }

    /**
     * Up to 10 prior messages (English) for short-horizon continuity.
     */
    private function buildRecentDialog(TriageSession $session, TriageMessage $currentUserMessage): string
    {
        $prior = $session->messages()
            ->where('id', '<', $currentUserMessage->id)
            ->reorder()
            ->orderByDesc('id')
            ->limit(10)
            ->get(['role', 'content'])
            ->reverse()
            ->values();

        if ($prior->isEmpty()) {
            return '';
        }

        $lines = $prior->map(function (TriageMessage $message): string {
            $label = $message->role === TriageMessageRole::Assistant ? 'Assistant' : 'User';

            return "{$label}: {$message->content}";
        })->implode("\n");

        $locale = $session->locale->value;
        if ($locale === 'en') {
            return $lines;
        }

        return $this->translate($lines, $locale, 'en');
    }

    /**
     * @return array{transcript: string, engine: string|null}
     */
    private function speechToText(UploadedFile $audio, string $locale): array
    {
        $baseUrl = rtrim((string) config('services.modal.url'), '/');
        $bytes = file_get_contents($audio->getRealPath()) ?: '';

        try {
            $response = Http::timeout(120)->post("{$baseUrl}/api/v1/transcribe", [
                'audio_b64' => base64_encode($bytes),
                'language' => $locale,
            ]);

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

    private function translate(string $text, string $from, string $to): string
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey || trim($text) === '' || $from === $to) {
            return $text;
        }

        $model = (string) (config('services.triage.translate_model') ?: 'gpt-4o-mini');
        $fromLabel = $this->languageLabel($from);
        $toLabel = $this->languageLabel($to);

        try {
            $response = Http::withToken((string) $apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a medical triage translator. Translate faithfully from '
                                .$fromLabel.' to '.$toLabel
                                .'. Preserve negation and severity. Do not add symptoms, advice, or diagnoses. '
                                .'Return only the translation text.',
                        ],
                        ['role' => 'user', 'content' => $text],
                    ],
                ]);

            $out = trim((string) $response->json('choices.0.message.content'));
            if ($out !== '') {
                return $out;
            }
        } catch (\Throwable $e) {
            Log::warning('Triage translate failed', ['error' => $e->getMessage()]);
        }

        return $text;
    }

    private function textToSpeech(string $text): ?string
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey || trim($text) === '') {
            return null;
        }

        $model = (string) config('services.triage.tts_model', 'gpt-4o-mini-tts');
        $voice = (string) config('services.triage.tts_voice', 'coral');
        $speed = (float) config('services.triage.tts_speed', 1.2);
        $speed = max(0.25, min(4.0, $speed));
        $instructions = (string) config(
            'services.triage.tts_instructions',
            'Sound like a real clinician talking face to face, not a phone menu or chatbot. Warm, human, and lightly conversational. Vary intonation naturally; avoid flat monotone, rigid cadence, or over-enunciated announcer style. Keep a brisk everyday speaking pace. Soften list-heavy medical wording so it feels spoken, not read aloud from a script.',
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
            $modality = ($record->detected_modality ?? $record->modality)?->label() ?? 'Unknown';
            $date = ($record->analyzed_at ?? $record->created_at)?->toDateString() ?? '-';
            $summary = '';
            if (is_array($record->physician_report) && isset($record->physician_report['summary'])) {
                $summary = trim((string) $record->physician_report['summary']);
            }
            if ($summary === '' && is_array($record->findings)) {
                $labels = collect($record->findings)->pluck('label')->filter()->take(5)->implode(', ');
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
    private function fallbackTriage(string $userMessageEn, string $summary): array
    {
        $lower = mb_strtolower($userMessageEn);
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

        $newSummary = trim($summary."\nUser: {$userMessageEn}\nAssistant: {$assistant}");

        return [
            'draft' => $assistant,
            'structured' => [
                'assistant_message' => $assistant,
                'urgency' => $urgency,
                'chief_complaint' => mb_substr($userMessageEn, 0, 120),
                'summary' => mb_substr($newSummary, 0, 2000),
                'suggested_followups' => [
                    'How long have you had these symptoms?',
                    'Do you have any chronic conditions?',
                    'Are you taking any medications?',
                ],
                'done' => false,
            ],
        ];
    }

    private function languageLabel(string $code): string
    {
        return match ($code) {
            'ms' => 'Bahasa Melayu',
            'zh' => 'Mandarin Chinese',
            'ta' => 'Tamil',
            default => 'English',
        };
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
