<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Jobs\FailStaleAnalysis;
use App\Jobs\ProcessMedicalRecord;
use App\Models\AnalysisJob;
use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;
use App\Services\RagService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('remote pipeline accepts analyze job via Http fake', function () {
    Queue::fake();
    $rag = app(RagService::class);
    $vector = $rag->localHashEmbed('Right lower lobe opacity consolidation pneumonia chest radiograph cardiomegaly');
    config(['services.openai.api_key' => 'test-key']);
    Http::fake([
        '*/api/v1/analyze' => Http::response(['job_id' => 'x', 'status' => 'accepted'], 200),
        'https://api.openai.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => $vector]],
        ], 200),
    ]);

    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Tuberculosis 4th Edition',
        'section' => 'Key messages',
        'content' => 'Right lower lobe opacity consolidation pneumonia chest radiograph cardiomegaly',
        'embedding' => $vector,
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

    $steps = collect($record->fresh()->pipeline_steps);
    expect($steps->firstWhere('step', 'upload')['status'])->toBe('completed')
        ->and($steps->firstWhere('step', 'deidentify')['status'])->toBe('running')
        ->and($steps->firstWhere('step', 'route')['status'])->toBe('pending')
        ->and($steps->firstWhere('step', 'analyze')['status'])->toBe('pending');

    $queued = new ProcessMedicalRecord($record->fresh(), $job->fresh());
    $queued->handle($pipeline);

    $record->refresh();
    $job->refresh();

    expect($record->status)->toBe(RecordStatus::Processing)
        ->and($record->deidentified_at)->not->toBeNull()
        ->and($record->safe_file_path)->not->toBeNull()
        ->and($job->status)->toBe('running');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/analyze')) {
            return false;
        }
        $body = $request->data();

        return is_array($body)
            && ! empty($body['file_b64'])
            && empty($body['file_url'] ?? null)
            && base64_decode((string) $body['file_b64'], true) !== false;
    });

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
        'medgemma_draft' => "FINDINGS:\nPatchy right lower lobe opacity.\n\nIMPRESSION:\nPossible pneumonia.",
        'patient_report' => [
            'summary' => 'The scan shows a patchy area in the right lower lung.',
            'what_this_means' => 'This can be infection; your doctor will decide.',
            'questions_for_doctor' => ['Do I need antibiotics?'],
            'action_plan' => ['See your doctor if fever continues.'],
        ],
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
    'picture is not ct' => ['picture.jpg', 'image/jpeg', Modality::Xray],
    'doctor is not ct' => ['doctor.jpg', 'image/jpeg', Modality::Xray],
    'oct token is ophthalmology' => ['oct_od.jpg', 'image/jpeg', Modality::Ophthalmology],
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

test('lab pdf with a text layer sends lab_text instead of file bytes', function () {
    Queue::fake();
    Process::fake([
        '*' => Process::result(output: "Haemoglobin  13.3  g/dL  11.5-16.5\nWBC  12.1  x10^9/L  4.0-11.0\nPlatelets  250  x10^9/L  150-400\n"),
    ]);
    Http::fake([
        '*/api/v1/analyze' => Http::response(['job_id' => 'lab', 'status' => 'accepted'], 200),
    ]);

    Storage::fake('local');
    $physician = User::factory()->physician()->create();
    $path = 'medical-records/cbc.pdf';
    Storage::disk('local')->put($path, '%PDF-1.4 lab');

    $record = MedicalRecord::factory()->create([
        'user_id' => $physician->id,
        'uploaded_by_user_id' => $physician->id,
        'modality' => Modality::LabPdf,
        'status' => RecordStatus::Pending,
        'file_path' => $path,
        'mime_type' => 'application/pdf',
        'original_filename' => 'cbc.pdf',
    ]);

    $pipeline = app(AiPipelineService::class);
    $job = $pipeline->dispatch($record);
    (new ProcessMedicalRecord($record->fresh(), $job->fresh()))->handle($pipeline);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/api/v1/analyze')) {
            return false;
        }
        $body = $request->data();

        return is_array($body)
            && str_contains((string) ($body['lab_text'] ?? ''), 'Haemoglobin')
            && empty($body['file_b64'] ?? null);
    });
});

test('analyze handoff retries a cold-start 502 then accepts', function () {
    Queue::fake();
    Http::fake([
        '*/api/v1/analyze' => Http::sequence()
            ->push(['message' => 'cold'], 502)
            ->push(['job_id' => 'x', 'status' => 'accepted'], 200),
    ]);

    Storage::fake('local');
    $path = 'medical-records/test-scan.jpg';
    Storage::disk('local')->put($path, 'jpeg-bytes');

    $record = MedicalRecord::factory()->create([
        'modality' => Modality::Xray,
        'status' => RecordStatus::Pending,
        'file_path' => $path,
        'mime_type' => 'image/jpeg',
    ]);

    $pipeline = app(AiPipelineService::class);
    $job = $pipeline->dispatch($record);
    (new ProcessMedicalRecord($record->fresh(), $job->fresh()))->handle($pipeline);

    expect($record->fresh()->status)->toBe(RecordStatus::Processing);
    Queue::assertPushed(FailStaleAnalysis::class);
});

test('stale analysis watchdog fails a stuck processing record', function () {
    $record = MedicalRecord::factory()->create([
        'status' => RecordStatus::Processing,
    ]);
    $job = AnalysisJob::factory()->create([
        'medical_record_id' => $record->id,
        'status' => 'running',
        'external_job_id' => 'stuck-job',
        'started_at' => now()->subMinutes(21),
    ]);

    (new FailStaleAnalysis($job->external_job_id))->handle();

    expect($record->fresh()->status)->toBe(RecordStatus::Failed)
        ->and($job->fresh()->status)->toBe('failed');
});

test('stale analysis watchdog ignores a fresh handoff', function () {
    $record = MedicalRecord::factory()->create([
        'status' => RecordStatus::Processing,
    ]);
    $job = AnalysisJob::factory()->create([
        'medical_record_id' => $record->id,
        'status' => 'running',
        'external_job_id' => 'fresh-job',
        'started_at' => now(),
    ]);

    (new FailStaleAnalysis($job->external_job_id))->handle();

    expect($record->fresh()->status)->toBe(RecordStatus::Processing)
        ->and($job->fresh()->status)->toBe('running');
});

test('physician can retry a failed analysis', function () {
    Queue::fake();
    $physician = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $physician->id,
        'uploaded_by_user_id' => $physician->id,
        'status' => RecordStatus::Failed,
        'error_message' => 'AI service rejected analyze request: HTTP 502',
    ]);

    $this->actingAs($physician)
        ->post(route('records.retry', $record))
        ->assertRedirect(route('records.show', $record));

    expect($record->fresh()->status)->toBe(RecordStatus::Processing)
        ->and($record->fresh()->error_message)->toBeNull();
    Queue::assertPushed(ProcessMedicalRecord::class);
});
