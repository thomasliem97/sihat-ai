<?php

namespace App\Jobs;

use App\Models\TriageSession;
use App\Models\User;
use App\Services\TriageTurnStatus;
use App\Services\VoiceTriageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessTriageVoiceTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public int $sessionId,
        public int $userId,
        public string $audioPath,
    ) {}

    public function handle(VoiceTriageService $triage): void
    {
        $session = TriageSession::query()->find($this->sessionId);
        $user = User::query()->find($this->userId);

        if ($session === null || $user === null) {
            TriageTurnStatus::clear($this->sessionId);
            $this->deleteAudio();

            return;
        }

        if (! Storage::disk('local')->exists($this->audioPath)) {
            TriageTurnStatus::markFailed($this->sessionId, 'Recording was lost. Please try again.');
            $this->deleteAudio();

            return;
        }

        $absolute = Storage::disk('local')->path($this->audioPath);
        $audio = new UploadedFile(
            $absolute,
            'triage.webm',
            'audio/webm',
            null,
            true,
        );

        try {
            $triage->sendMessage(
                $session,
                $user,
                text: null,
                audio: $audio,
                withSpeech: false,
            );
            TriageTurnStatus::clear($this->sessionId);
        } catch (\InvalidArgumentException $e) {
            TriageTurnStatus::markFailed($this->sessionId, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Triage voice turn failed', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
            ]);
            TriageTurnStatus::markFailed(
                $this->sessionId,
                'Could not process voice message. Please try again.',
            );
        } finally {
            $this->deleteAudio();
        }
    }

    public function failed(?\Throwable $e): void
    {
        TriageTurnStatus::markFailed(
            $this->sessionId,
            'Could not process voice message. Please try again.',
        );
        $this->deleteAudio();
    }

    private function deleteAudio(): void
    {
        Storage::disk('local')->delete($this->audioPath);
    }
}
