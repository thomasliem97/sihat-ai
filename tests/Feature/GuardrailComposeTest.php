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

    expect($flags['flags'])->toContain('low_confidence_abstention')
        ->and($flags['code'])->toBe('WARN')
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

    expect($flags['flags'])->toContain('critical_value_escalation')
        ->and($flags['flags'])->toContain('confidence_publish')
        ->and($flags['code'])->toBe('WARN')
        ->and($reports['patient_report'])->toBeNull();
});

test('weak guideline grounding stays ALLOW and keeps patient copy', function () {
    $pipeline = app(AiPipelineService::class);
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
        'modality' => Modality::Xray,
    ]);

    $result = [
        'findings' => [
            ['label' => 'Opacity', 'severity' => 'abnormal', 'confidence' => 0.9],
        ],
        'overall_confidence' => 0.9,
        'rag_weak' => true,
        'patient_report' => [
            'summary' => 'The scan shows a patchy area in the right lower lung.',
            'what_this_means' => 'Your doctor will decide next steps.',
            'questions_for_doctor' => [],
            'action_plan' => [],
        ],
    ];

    $flags = $pipeline->applyGuardrails($result);
    $reports = $pipeline->composeReports($record, $result, $flags);

    expect($flags['flags'])->toContain('weak_guideline_grounding')
        ->and($flags['code'])->toBe('ALLOW')
        ->and($reports['patient_report']['summary'])->toContain('patchy area');
});

test('hedge band is ALLOW and does not invent a patient template', function () {
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

    expect($flags['flags'])->toContain('confidence_hedge')
        ->and($flags['code'])->toBe('ALLOW')
        ->and($reports['patient_report'])->toBeNull();
});

test('compose keeps medgemma findings and impression instead of label stuffing', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
        'modality' => Modality::Xray,
    ]);

    $draft = "FINDINGS:\nLungs are clear. No pneumothorax or effusion. Heart size is normal.\n\nIMPRESSION:\nNo acute cardiopulmonary process.";

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal', 'confidence' => 0.9]],
        'overall_confidence' => 0.9,
        'citations' => [],
        'medgemma_draft' => $draft,
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['physician_report']['findings_narrative'])->toContain('No pneumothorax')
        ->and($reports['physician_report']['impression'])->toBe('No acute cardiopulmonary process.')
        ->and($reports['physician_report']['summary'])->toBe('No acute cardiopulmonary process.')
        ->and($reports['physician_report']['medgemma_draft'])->toBe($draft)
        ->and($reports['physician_report']['summary'])->not->toContain('Key findings:')
        ->and($reports['patient_report'])->toBeNull();
});

test('compose persists a structured patient report from the draft', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
        'modality' => Modality::Xray,
    ]);

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal', 'confidence' => 0.9]],
        'overall_confidence' => 0.9,
        'citations' => [],
        'medgemma_draft' => "FINDINGS:\nPatchy right lower lobe opacity.\n\nIMPRESSION:\nPossible pneumonia; correlate clinically.",
        'recommendations' => ['Correlate with fever and WBC.'],
        'patient_report' => [
            'summary' => 'The scan shows a patchy area in the right lower lung.',
            'what_this_means' => 'This can be infection; your doctor will decide.',
            'questions_for_doctor' => ['Do I need antibiotics?'],
            'action_plan' => ['See your doctor if fever continues.'],
        ],
        'adapter_used' => false,
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['patient_report']['summary'])->toBe('The scan shows a patchy area in the right lower lung.')
        ->and($reports['physician_report']['recommendations'])->toContain('Correlate with fever and WBC.')
        ->and($reports['physician_report']['recommendations'])->toContain('Correlate clinically; do not treat AI output as a final diagnosis.')
        ->and($reports['physician_report']['technical_notes'])->toContain('not used on this report');
});

test('patient show withholds report when critical flag set', function () {
    $patient = User::factory()->patient()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'user_id' => $patient->id,
        'status' => RecordStatus::Completed,
        'patient_report' => ['summary' => 'secret'],
        'physician_report' => ['summary' => 'clinical'],
        'guardrail_flags' => [
            'code' => 'WARN',
            'flags' => ['critical_value_escalation', 'medical_disclaimer_required', 'not_a_diagnosis'],
        ],
        'findings' => [['label' => 'Critical finding', 'severity' => 'critical']],
        'signed_at' => now(),
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

test('compose puts retrieved guideline excerpts on the physician report', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
        'modality' => Modality::Xray,
    ]);

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal', 'confidence' => 0.9]],
        'overall_confidence' => 0.9,
        'citations' => [
            [
                'source' => 'MOH QR - Management of Tuberculosis 4th Edition',
                'section' => 'Key messages',
                'excerpt' => 'Chest radiograph should be done in people with suspected EPTB to rule out concomitant PTB.…',
                'relevance' => 0.71,
            ],
        ],
        'medgemma_draft' => "FINDINGS:\nPatchy right lower lobe opacity.\n\nIMPRESSION:\nPossible infection; correlate clinically.",
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['physician_report']['findings_narrative'])->toBe('Patchy right lower lobe opacity.')
        ->and($reports['physician_report']['impression'])->toBe('Possible infection; correlate clinically.')
        ->and($reports['physician_report']['summary'])->toContain('Possible infection; correlate clinically.')
        ->and($reports['physician_report']['summary'])->toContain('Chest radiograph should be done')
        ->and($reports['physician_report']['summary'])->not->toContain('Guidelines:')
        ->and($reports['physician_report']['guideline_grounding'][0]['source'])->toContain('Tuberculosis')
        ->and($reports['physician_report']['guideline_grounding'][0]['excerpt'])->toContain('Chest radiograph');
});

test('composeReports cuts a cycling numbered impression', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::English,
        'modality' => Modality::Xray,
    ]);

    $draft = <<<'TXT'
FINDINGS:
Nodular opacities in the right upper, right lower, and left lower lobes.

IMPRESSION:
1) Nodular opacities in the right upper lobe, right lower lobe, and left lower lobe. Recommend CT chest.
2) No pneumothorax or pleural effusion.
3) No cardiomegaly.
4) No mediastinal emergency.
5) No tension physiology.
6) No large pneumothorax.
7) No mediastinal emergency.
8) No tension physiology.
TXT;

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Pulmonary nodular opacities', 'severity' => 'abnormal', 'confidence' => 0.9]],
        'overall_confidence' => 0.9,
        'citations' => [],
        'medgemma_draft' => $draft,
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['physician_report']['impression'])->toContain('Recommend CT chest')
        ->and($reports['physician_report']['impression'])->not->toContain('7)')
        ->and(substr_count(strtolower($reports['physician_report']['impression']), 'no mediastinal emergency'))->toBe(1)
        ->and($reports['physician_report']['summary'])->not->toContain('7)');
});
