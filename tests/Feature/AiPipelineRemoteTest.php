<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Jobs\ProcessMedicalRecord;
use App\Models\AnalysisJob;
use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;
use App\Services\RagService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('remote pipeline accepts analyze job via Http fake', function () {
    Queue::fake();
    Http::fake([
        '*/api/v1/analyze' => Http::response(['job_id' => 'x', 'status' => 'accepted'], 200),
    ]);

    $rag = app(RagService::class);
    GuidelineChunk::create([
        'source' => 'MOH Malaysia CPG - Community Acquired Pneumonia',
        'section' => '4.2 Diagnosis',
        'content' => 'Right lower lobe opacity consolidation pneumonia chest radiograph cardiomegaly',
        'embedding' => $rag->localHashEmbed('Right lower lobe opacity consolidation pneumonia chest radiograph cardiomegaly'),
    ]);

    Storage::fake('local');
    $physician = User::factory()->physician()->create();
    $path = 'medical-records/test-scan.jpg';
    Storage::disk('local')->put($path, 'jpeg-bytes');

    $record = MedicalRecord::factory()->create([
        'user_id' => $physician->id,
        'uploaded_by_user_id' => $physician->id,
        'modality' => Modality::Xray,
        'status' => RecordStatus::Pending,
        'file_path' => $path,
        'mime_type' => 'image/jpeg',
    ]);

    $pipeline = app(AiPipelineService::class);
    $job = $pipeline->dispatch($record);

    expect($job)->toBeInstanceOf(AnalysisJob::class);
    Queue::assertPushed(ProcessMedicalRecord::class);

    $queued = new ProcessMedicalRecord($record->fresh(), $job->fresh());
    $queued->handle($pipeline);

    $record->refresh();
    $job->refresh();

    expect($record->status)->toBe(RecordStatus::Processing)
        ->and($record->deidentified_at)->not->toBeNull()
        ->and($record->safe_file_path)->not->toBeNull()
        ->and($job->status)->toBe('running');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/analyze'));

    // Simulate FastAPI webhook completion
    $result = $pipeline->completeFromWebhook($record->fresh(), $job->fresh(), [
        'findings' => [
            [
                'label' => 'Right lower lobe opacity',
                'description' => 'Patchy opacity',
                'confidence' => 0.88,
                'severity' => 'abnormal',
            ],
        ],
        'overall_confidence' => 0.88,
        'engine' => 'medgemma',
        'adapter' => 'none',
        'bounding_boxes' => [],
    ]);
    $pipeline->persistCompleted($record->fresh(), $job->fresh(), $result);

    $record->refresh();
    expect($record->status)->toBe(RecordStatus::Completed)
        ->and($record->physician_report)->not->toBeNull()
        ->and($record->patient_report)->not->toBeNull()
        ->and($record->physician_report['engine'] ?? null)->toBe('medgemma');
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

test('detect modality uses specific-first filename hints', function (string $filename, string $mime, Modality $expected) {
    $pipeline = app(AiPipelineService::class);

    $record = MedicalRecord::factory()->make([
        'modality' => Modality::Unknown,
        'mime_type' => $mime,
        'original_filename' => $filename,
        'file_path' => '',
    ]);

    expect($pipeline->detectModality($record)['modality'])->toBe($expected);
})->with([
    'fundus' => ['fundus_od.jpg', 'image/jpeg', Modality::Ophthalmology],
    'melanoma isic' => ['melanoma_isic.jpg', 'image/jpeg', Modality::Dermatology],
    'thyroid histo' => ['thyroid_histo.png', 'image/png', Modality::Histopath],
    'chest ct before xray' => ['chest_ct_slice.png', 'image/png', Modality::Ct],
    'lab pdf' => ['report.pdf', 'application/pdf', Modality::LabPdf],
    'mri' => ['brain_mri_t2.jpg', 'image/jpeg', Modality::Mri],
]);

test('detect modality reads ct code from dicom bytes', function () {
    Storage::fake('local');

    $path = 'medical-records/fixture-ct.dcm';
    $bytes = 'DICM'.str_repeat("\0", 64)."\x08\x00\x60\x00CS\x02\x00CT".str_repeat("\0", 32);
    Storage::disk('local')->put($path, $bytes);

    $pipeline = app(AiPipelineService::class);
    $record = MedicalRecord::factory()->make([
        'modality' => Modality::Unknown,
        'mime_type' => 'application/dicom',
        'original_filename' => 'study.dcm',
        'file_path' => $path,
    ]);

    expect($pipeline->detectModality($record)['modality'])->toBe(Modality::Ct)
        ->and($pipeline->detectModality($record)['confidence'])->toBeGreaterThanOrEqual(0.9);
});
