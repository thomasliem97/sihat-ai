<?php

namespace App\Jobs;

use App\Enums\RecordStatus;
use App\Models\AnalysisJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FailStaleAnalysis implements ShouldQueue
{
    use Queueable;

    public const STALE_AFTER_MINUTES = 20;

    public int $tries = 1;

    public function __construct(
        public string $externalJobId,
    ) {}

    public function handle(): void
    {
        $job = AnalysisJob::query()
            ->where('external_job_id', $this->externalJobId)
            ->first();

        if ($job === null || in_array($job->status, ['completed', 'failed'], true)) {
            return;
        }

        if ($job->started_at !== null && $job->started_at->gt(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
            return;
        }

        $record = $job->medicalRecord;
        if ($record === null || ! in_array($record->status, [RecordStatus::Pending, RecordStatus::Processing], true)) {
            return;
        }

        $message = 'Analysis timed out waiting for the model. Retry from this page.';

        $record->update([
            'status' => RecordStatus::Failed,
            'error_message' => $message,
        ]);

        $job->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
