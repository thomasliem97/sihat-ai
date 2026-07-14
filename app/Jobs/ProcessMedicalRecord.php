<?php

namespace App\Jobs;

use App\Enums\ClinicalFlag;
use App\Enums\RecordStatus;
use App\Models\AnalysisJob;
use App\Models\Biomarker;
use App\Models\MedicalRecord;
use App\Services\AiPipelineService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessMedicalRecord implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public MedicalRecord $record,
        public AnalysisJob $analysisJob,
    ) {}

    public function handle(AiPipelineService $pipeline): void
    {
        try {
            $pipeline->beginRemoteAnalysis($this->record, $this->analysisJob);
        } catch (\Throwable $e) {
            Log::error('Medical record analysis failed', [
                'record_id' => $this->record->id,
                'error' => $e->getMessage(),
            ]);

            $this->record->update([
                'status' => RecordStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            $this->analysisJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $biomarkers
     */
    public function persistBiomarkers(array $biomarkers): void
    {
        foreach ($biomarkers as $data) {
            Biomarker::create([
                'user_id' => $this->record->user_id,
                'medical_record_id' => $this->record->id,
                'name' => $data['name'],
                'value' => $data['value'],
                'unit' => $data['unit'],
                'reference_low' => $data['reference_low'] ?? null,
                'reference_high' => $data['reference_high'] ?? null,
                'status' => ClinicalFlag::from($data['status']),
                'collected_at' => now(),
            ]);
        }
    }
}
