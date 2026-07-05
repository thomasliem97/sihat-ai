<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;

test('guardrails abstain below 0.50 and withhold patient report', function () {
    $pipeline = app(AiPipelineService::class);
    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'language' => ReportLanguage::English,
        'modality' => Modality::Xray,
    ]);

    $result = [
        'findings' => [
            ['label' => 'Unclear opacity', 'severity' => 'borderline', 'confidence' => 0.4],
        ],
        'overall_confidence' => 0.42,
        'citations' => [],
        'rag_weak' => true,
    ];

    $flags = $pipeline->applyGuardrails($result);
    $reports = $pipeline->composeReports($record, $result, $flags);

    expect($flags)->toContain('low_confidence_abstention')
        ->and($reports['patient_report'])->toBeNull()
        ->and($reports['physician_report'])->not->toBeNull();
});

test('guardrails escalate critical and withhold patient copy', function () {
    $pipeline = app(AiPipelineService::class);
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
    ]);

    $result = [
        'findings' => [
            ['label' => 'Tension pneumothorax', 'severity' => 'critical', 'confidence' => 0.91],
        ],
        'overall_confidence' => 0.91,
        'biomarkers' => [],
    ];

    $flags = $pipeline->applyGuardrails($result);
    $reports = $pipeline->composeReports($record, $result, $flags);

    expect($flags)->toContain('critical_value_escalation')
        ->and($flags)->toContain('confidence_publish')
        ->and($reports['patient_report'])->toBeNull();
});

test('hedge band softens patient language between 0.50 and 0.80', function () {
    $pipeline = app(AiPipelineService::class);
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
    ]);

    $result = [
        'findings' => [
            ['label' => 'Possible infiltrate', 'severity' => 'borderline', 'confidence' => 0.66],
        ],
        'overall_confidence' => 0.66,
    ];

    $flags = $pipeline->applyGuardrails($result);
    $reports = $pipeline->composeReports($record, $result, $flags);

    expect($flags)->toContain('confidence_hedge')
        ->and($reports['patient_report']['summary'])->toContain('preliminary');
});

test('patient show withholds report when critical flag set', function () {
    $patient = User::factory()->patient()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'user_id' => $patient->id,
        'status' => RecordStatus::Completed,
        'patient_report' => ['summary' => 'secret'],
        'physician_report' => ['summary' => 'clinical'],
        'guardrail_flags' => ['critical_value_escalation', 'medical_disclaimer_required', 'not_a_diagnosis'],
        'findings' => [['label' => 'Critical finding', 'severity' => 'critical']],
    ]);

    $this->actingAs($patient)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('records/Show')
            ->where('record.patient_report_withheld', true)
            ->where('record.patient_report', null)
        );
});
