<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Jobs\ProcessMedicalRecord;
use App\Models\AnalysisJob;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;
use Illuminate\Support\Facades\Queue;

test('mock pipeline completes a record synchronously', function () {
    config(['services.sihat_ai.use_mock' => true]);

    Queue::fake();

    $physician = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $physician->id,
        'uploaded_by_user_id' => $physician->id,
        'modality' => Modality::Xray,
        'status' => RecordStatus::Pending,
    ]);

    $pipeline = app(AiPipelineService::class);
    $job = $pipeline->dispatch($record);

    expect($job)->toBeInstanceOf(AnalysisJob::class);
    Queue::assertPushed(ProcessMedicalRecord::class);

    $queued = new ProcessMedicalRecord($record->fresh(), $job->fresh());
    $queued->handle($pipeline);

    $record->refresh();
    $job->refresh();

    expect($record->status)->toBe(RecordStatus::Completed)
        ->and($record->findings)->not->toBeEmpty()
        ->and($record->physician_report)->not->toBeNull()
        ->and($record->patient_report)->not->toBeNull()
        ->and($record->deidentified_at)->not->toBeNull()
        ->and($job->status)->toBe('completed');
});

test('detect modality routes pdf to lab and derm filename to dermatology', function () {
    $pipeline = app(AiPipelineService::class);

    $lab = MedicalRecord::factory()->make([
        'modality' => Modality::Unknown,
        'mime_type' => 'application/pdf',
        'original_filename' => 'report.pdf',
    ]);
    $derm = MedicalRecord::factory()->make([
        'modality' => Modality::Unknown,
        'mime_type' => 'image/jpeg',
        'original_filename' => 'skin-lesion-arm.jpg',
    ]);

    expect($pipeline->detectModality($lab)['modality'])->toBe(Modality::LabPdf)
        ->and($pipeline->detectModality($derm)['modality'])->toBe(Modality::Dermatology);
});
