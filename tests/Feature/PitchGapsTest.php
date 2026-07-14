<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Models\AnalysisJob;
use App\Models\AuditEvent;
use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;
use App\Services\DeidentificationService;
use App\Services\RagService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

test('guardrails return ALLOW or WARN code and veto patient prose on WARN', function () {
    $pipeline = app(AiPipelineService::class);
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
    ]);

    $critical = [
        'findings' => [
            ['label' => 'Tension pneumothorax', 'severity' => 'critical', 'confidence' => 0.91],
        ],
        'overall_confidence' => 0.91,
        'engine' => 'medgemma',
    ];

    $guardrails = $pipeline->applyGuardrails($critical);
    $reports = $pipeline->composeReports($record, $critical, $guardrails);

    expect($guardrails['code'])->toBe('WARN')
        ->and($guardrails['flags'])->toContain('critical_value_escalation')
        ->and($reports['patient_report'])->toBeNull()
        ->and($reports['physician_report']['guardrail_code'])->toBe('WARN')
        ->and($reports['physician_report']['engine'])->toBe('medgemma');
});

test('critical escalation writes audit event', function () {
    Notification::fake();

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $patient->id,
        'uploaded_by_user_id' => $physician->id,
        'modality' => Modality::Xray,
        'language' => ReportLanguage::English,
    ]);
    Storage::fake('local');
    Storage::disk('local')->put($record->file_path, 'fake');

    $pipeline = app(AiPipelineService::class);
    $job = AnalysisJob::factory()->create(['medical_record_id' => $record->id]);

    $result = $pipeline->completeFromWebhook($record, $job, [
        'findings' => [
            ['label' => 'Tension pneumothorax', 'severity' => 'critical', 'confidence' => 0.95],
        ],
        'overall_confidence' => 0.95,
        'engine' => 'medgemma',
        'adapter' => 'none',
    ]);
    $pipeline->persistCompleted($record->fresh(), $job->fresh(), $result);

    expect(AuditEvent::where('event', 'critical_value_escalation')->where('medical_record_id', $record->id)->exists())
        ->toBeTrue();
});

test('patient report awaits physician signature', function () {
    $patient = User::factory()->patient()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'user_id' => $patient->id,
        'status' => RecordStatus::Completed,
        'patient_report' => ['summary' => 'ready'],
        'physician_report' => ['summary' => 'clinical'],
        'guardrail_flags' => [
            'code' => 'ALLOW',
            'flags' => ['medical_disclaimer_required', 'not_a_diagnosis', 'confidence_publish'],
        ],
        'signed_at' => null,
    ]);

    $this->actingAs($patient)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('records/Show')
            ->where('record.patient_awaiting_sign', true)
            ->where('record.patient_report', null)
        );
});

test('signed patient can see report when not WARN', function () {
    $patient = User::factory()->patient()->create();
    $physician = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'user_id' => $patient->id,
        'status' => RecordStatus::Completed,
        'patient_report' => ['summary' => 'ready'],
        'physician_report' => ['summary' => 'clinical'],
        'guardrail_flags' => [
            'code' => 'ALLOW',
            'flags' => ['medical_disclaimer_required', 'not_a_diagnosis', 'confidence_publish'],
        ],
        'signed_at' => now(),
        'signed_by' => $physician->id,
    ]);

    $this->actingAs($patient)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('records/Show')
            ->where('record.patient_awaiting_sign', false)
            ->where('record.patient_report.summary', 'ready')
        );
});

test('discharge pdf routes to clinical_document', function () {
    $pipeline = app(AiPipelineService::class);
    $record = MedicalRecord::factory()->make([
        'modality' => Modality::Unknown,
        'mime_type' => 'application/pdf',
        'original_filename' => 'discharge_summary_ward3.pdf',
        'file_path' => '',
    ]);

    expect($pipeline->detectModality($record)['modality'])->toBe(Modality::ClinicalDocument);
});

test('deidentify writes safe_file_path sibling', function () {
    Storage::fake('local');
    $record = MedicalRecord::factory()->create([
        'file_path' => 'medical-records/original.jpg',
        'mime_type' => 'image/jpeg',
        'original_filename' => 'patient-900101-14-5678.jpg',
    ]);
    Storage::disk('local')->put($record->file_path, 'jpeg-bytes');

    app(DeidentificationService::class)->deidentify($record);
    $record->refresh();

    expect($record->safe_file_path)->not->toBeNull()
        ->and($record->safe_file_path)->not->toBe($record->file_path)
        ->and(Storage::disk('local')->exists($record->safe_file_path))->toBeTrue()
        ->and($record->original_filename)->toContain('[REDACTED]');
});

test('honest technical notes report engine truthfully', function () {
    $pipeline = app(AiPipelineService::class);
    $record = MedicalRecord::factory()->create(['language' => ReportLanguage::English]);
    $reports = $pipeline->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal']],
        'overall_confidence' => 0.9,
        'citations' => [],
        'engine' => 'medgemma',
        'adapter' => 'none',
    ], ['code' => 'ALLOW', 'flags' => ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']]);

    expect($reports['physician_report']['technical_notes'])->toContain('engine=medgemma')
        ->and($reports['physician_report']['technical_notes'])->not->toContain('Analysis via MedGemma 1.5 (Modal)');
});

test('partial findings include imaging specialist for xray', function () {
    Notification::fake();
    config(['services.sihat_ai.webhook_secret' => 'test-secret']);

    GuidelineChunk::create([
        'source' => 'MOH Malaysia CPG - Community Acquired Pneumonia',
        'section' => '4.2 Diagnosis',
        'content' => 'Chest radiograph may show lobar or patchy consolidation.',
        'embedding' => app(RagService::class)->localHashEmbed('pneumonia consolidation chest'),
    ]);

    $user = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'status' => RecordStatus::Processing,
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'deidentified_at' => now(),
    ]);
    $job = AnalysisJob::factory()->create([
        'medical_record_id' => $record->id,
        'status' => 'running',
        'external_job_id' => 'partial-job-1',
    ]);

    $result = app(AiPipelineService::class)->completeFromWebhook($record, $job, [
        'findings' => [
            ['label' => 'Right lower lobe opacity', 'severity' => 'abnormal', 'confidence' => 0.88],
        ],
        'overall_confidence' => 0.88,
        'engine' => 'medgemma',
    ]);
    app(AiPipelineService::class)->persistCompleted($record->fresh(), $job->fresh(), $result);
    $record->refresh();

    $hops = collect($record->agent_trace)->pluck('hop')->all();

    expect($record->partial_findings)->toHaveKey('imaging')
        ->and($hops)->toContain('imaging_specialist', 'doc_specialist', 'rag', 'guardrail', 'compose');
});
