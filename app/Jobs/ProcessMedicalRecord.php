<?php

namespace App\Jobs;

use App\Enums\RecordStatus;
use App\Models\AnalysisJob;
use App\Models\MedicalRecord;
use App\Services\AiPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessMedicalRecord implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public MedicalRecord $record,
        public AnalysisJob $analysisJob,
    ) {}

    public function handle(AiPipelineService $pipeline): void
    {
        $pipeline->beginRemoteAnalysis($this->record, $this->analysisJob);
    }

    public function failed(?\Throwable $e): void
    {
        $message = $e?->getMessage() ?? 'Analysis failed';

        Log::error('Medical record analysis failed', [
            'record_id' => $this->record->id,
            'error' => $message,
        ]);

        $this->record->update([
            'status' => RecordStatus::Failed,
            'error_message' => $message,
        ]);

        $this->analysisJob->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
