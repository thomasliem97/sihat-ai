<?php

namespace App\Http\Controllers;

use App\Enums\TriageRoleContext;
use App\Enums\TriageSessionStatus;
use App\Jobs\ProcessTriageVoiceTurn;
use App\Models\TriageMessage;
use App\Models\TriageSession;
use App\Models\User;
use App\Services\TriageTurnStatus;
use App\Services\VoiceTriageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class VoiceTriageController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        assert($user instanceof User);

        $ownSessions = TriageSession::query()
            ->where('user_id', $user->id)
            ->where('status', TriageSessionStatus::Active)
            ->with('subjectUser:id,name')
            ->withMax('messages', 'created_at')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn (TriageSession $s) => $this->sessionPayload($s, includeMessages: false));

        $sharedSessions = $user->isPhysician()
            ? TriageSession::query()
                ->whereNotNull('shared_at')
                ->where('user_id', '!=', $user->id)
                ->with(['user:id,name', 'subjectUser:id,name'])
                ->withMax('messages', 'created_at')
                ->latest('shared_at')
                ->limit(30)
                ->get()
                ->map(fn (TriageSession $s) => $this->sessionPayload($s, includeMessages: false))
            : collect();

        $patients = $user->isPhysician()
            ? User::query()->where('role', 'patient')->orderBy('name')->get(['id', 'name'])
            : collect();

        return Inertia::render('voice/Triage', [
            'sessions' => $ownSessions,
            'sharedSessions' => $sharedSessions,
            'patients' => $patients,
            'isPhysician' => $user->isPhysician(),
            'activeSessionId' => null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $this->authorize('create', TriageSession::class);

        $validated = $request->validate([
            'subject_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $role = $user->isPhysician() ? TriageRoleContext::Physician : TriageRoleContext::Patient;
        $subjectId = null;

        if ($role === TriageRoleContext::Patient) {
            $subjectId = $user->id;
        } elseif (! empty($validated['subject_user_id'])) {
            $subject = User::query()->findOrFail((int) $validated['subject_user_id']);
            abort_unless($subject->isPatient(), 422, 'Subject must be a patient.');
            $subjectId = $subject->id;
        }

        $session = TriageSession::query()->create([
            'user_id' => $user->id,
            'subject_user_id' => $subjectId,
            'role_context' => $role,
            'locale' => '',
            'status' => TriageSessionStatus::Active,
        ]);

        return response()->json([
            'session' => $this->sessionPayload($session->load('subjectUser:id,name'), includeMessages: true),
        ], 201);
    }

    public function session(Request $request, TriageSession $session): JsonResponse
    {
        $this->authorize('view', $session);
        $session->load(['messages', 'subjectUser:id,name', 'user:id,name']);

        return response()->json([
            'session' => $this->sessionPayload($session, includeMessages: true),
        ]);
    }

    public function message(Request $request, TriageSession $session, VoiceTriageService $triage): JsonResponse
    {
        $this->authorize('message', $session);

        $validated = $request->validate([
            'text' => ['nullable', 'string', 'max:5000'],
            'audio' => ['nullable', 'file', 'max:10240', 'mimetypes:audio/webm,audio/wav,audio/mpeg,audio/mp4,video/webm'],
        ]);

        if (empty($validated['text']) && ! $request->hasFile('audio')) {
            return response()->json(['message' => 'Provide text or audio.'], 422);
        }

        if ($request->hasFile('audio')) {
            $audio = $request->file('audio');
            assert($audio !== null);

            if ($audio->getSize() < 1500) {
                return response()->json([
                    'message' => 'Hold the mic and speak before releasing.',
                ], 422);
            }

            $pending = TriageTurnStatus::get($session->id);
            if (($pending['status'] ?? null) === 'processing') {
                return response()->json([
                    'message' => 'Still processing the previous voice message.',
                ], 409);
            }

            $path = $audio->store('triage-audio/'.$session->id, 'local');
            if ($path === false) {
                return response()->json(['message' => 'Could not store recording.'], 500);
            }

            TriageTurnStatus::markProcessing($session->id);
            ProcessTriageVoiceTurn::dispatch(
                $session->id,
                (int) $request->user()->id,
                $path,
            );

            $session->load(['messages', 'subjectUser:id,name', 'user:id,name']);

            return response()->json([
                'status' => 'processing',
                'session' => $this->sessionPayload($session, includeMessages: true),
                'pending_turn' => TriageTurnStatus::get($session->id),
            ], 202);
        }

        try {
            $result = $triage->sendMessage(
                $session,
                $request->user(),
                $validated['text'] ?? null,
                null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'session' => $this->sessionPayload($result['session'], includeMessages: true),
            'assistant_message' => [
                'id' => $result['message']->id,
                'role' => $result['message']->role->value,
                'content' => $result['message']->content,
                'created_at' => $result['message']->created_at?->toIso8601String(),
            ],
            'audio_base64' => $result['audio_base64'],
            'phases' => $result['phases'],
            'done' => $result['done'],
        ]);
    }

    public function archive(Request $request, TriageSession $session, VoiceTriageService $triage): JsonResponse
    {
        $this->authorize('archive', $session);
        $session = $triage->archive($session);

        return response()->json([
            'session' => $this->sessionPayload($session, includeMessages: true),
        ]);
    }

    public function share(Request $request, TriageSession $session, VoiceTriageService $triage): JsonResponse
    {
        $this->authorize('share', $session);
        $session = $triage->share($session);

        return response()->json([
            'session' => $this->sessionPayload($session, includeMessages: true),
        ]);
    }

    public function speak(
        Request $request,
        TriageSession $session,
        TriageMessage $message,
        VoiceTriageService $triage,
    ): JsonResponse {
        $this->authorize('view', $session);
        abort_unless($message->triage_session_id === $session->id, 404);

        try {
            $audioBase64 = $triage->speakMessage($message);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($audioBase64 === null) {
            return response()->json(['message' => 'Voice playback is unavailable.'], 503);
        }

        return response()->json([
            'audio_base64' => $audioBase64,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(TriageSession $session, bool $includeMessages): array
    {
        $lastMessageAt = $session->messages_max_created_at
            ?? ($includeMessages ? $session->messages->last()?->created_at : null);

        $payload = [
            'id' => $session->id,
            'role_context' => $session->role_context->value,
            'locale' => $session->locale,
            'status' => $session->status->value,
            'urgency' => $session->urgency?->value,
            'chief_complaint' => $session->chief_complaint,
            'summary' => $session->summary,
            'shared_at' => $session->shared_at?->toIso8601String(),
            'subject_user_id' => $session->subject_user_id,
            'subject_name' => $session->subjectUser?->name,
            'owner_name' => $session->user?->name,
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
            'last_message_at' => $lastMessageAt
                ? Carbon::parse($lastMessageAt)->toIso8601String()
                : null,
            'pending_turn' => TriageTurnStatus::get($session->id),
        ];

        if ($includeMessages) {
            $payload['messages'] = $session->messages->map(fn ($m) => [
                'id' => $m->id,
                'role' => $m->role->value,
                'content' => $m->content,
                'input_modality' => $m->input_modality->value,
                'stt_engine' => $m->stt_engine,
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values();
        }

        return $payload;
    }
}
