<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class VoiceTriageController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('voice/Triage');
    }

    public function transcribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transcript' => ['nullable', 'string', 'max:5000'],
            'audio' => ['nullable', 'file', 'max:10240', 'mimetypes:audio/webm,audio/wav,audio/mpeg,audio/mp4,video/webm'],
        ]);

        $transcript = trim((string) ($validated['transcript'] ?? ''));
        $engine = null;

        if ($request->hasFile('audio')) {
            $bytes = file_get_contents($request->file('audio')->getRealPath()) ?: '';
            $stt = $this->speechToText(base64_encode($bytes));
            $engine = $stt['engine'] ?? null;
            if (($stt['transcript'] ?? '') !== '') {
                $transcript = (string) $stt['transcript'];
            }
        }

        if ($transcript === '') {
            return response()->json([
                'message' => 'Provide audio or transcript text.',
            ], 422);
        }

        return response()->json([
            'transcript' => $transcript,
            'engine' => $engine,
            'triage' => $this->structureTriage($transcript),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function speechToText(string $audioB64): array
    {
        $baseUrl = rtrim((string) config('services.modal.url'), '/');

        try {
            $response = Http::timeout(120)->post("{$baseUrl}/api/v1/transcribe", [
                'audio_b64' => $audioB64,
            ]);

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Throwable $e) {
            Log::warning('STT proxy failed', ['error' => $e->getMessage()]);
        }

        return ['transcript' => '', 'engine' => 'unavailable'];
    }

    /**
     * @return array<string, mixed>
     */
    private function structureTriage(string $transcript): array
    {
        $lower = mb_strtolower($transcript);
        $urgency = 'routine';

        if (str_contains($lower, 'chest pain') || str_contains($lower, 'sesak nafas') || str_contains($lower, 'shortness of breath')) {
            $urgency = 'urgent';
        }
        if (str_contains($lower, 'unconscious') || str_contains($lower, 'pengsan') || str_contains($lower, 'not breathing')) {
            $urgency = 'emergency';
        }

        $apiKey = config('services.openai.api_key');
        if ($apiKey) {
            try {
                $response = Http::withToken($apiKey)
                    ->timeout(45)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Return JSON only: {urgency: routine|urgent|emergency, chief_complaint, suggested_questions: string[3]}. Not medical advice.',
                            ],
                            ['role' => 'user', 'content' => $transcript],
                        ],
                        'temperature' => 0.2,
                    ]);

                $text = (string) $response->json('choices.0.message.content');
                if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
                    $decoded = json_decode($m[0], true);
                    if (is_array($decoded)) {
                        return [
                            'urgency' => $decoded['urgency'] ?? $urgency,
                            'chief_complaint' => $decoded['chief_complaint'] ?? mb_substr($transcript, 0, 120),
                            'suggested_questions' => $decoded['suggested_questions'] ?? [
                                'How long have you had these symptoms?',
                                'Do you have any chronic conditions?',
                                'Are you taking any medications?',
                            ],
                            'disclaimer' => 'This triage is decision support only and is not medical advice.',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Triage LLM failed', ['error' => $e->getMessage()]);
            }
        }

        return [
            'urgency' => $urgency,
            'chief_complaint' => mb_substr($transcript, 0, 120),
            'suggested_questions' => [
                'How long have you had these symptoms?',
                'Do you have any chronic conditions?',
                'Are you taking any medications?',
            ],
            'disclaimer' => 'This triage is decision support only and is not medical advice.',
        ];
    }
}
