<?php

use App\Enums\ReportLanguage;
use App\Models\MedicalRecord;
use App\Services\AiPipelineService;

test('mandarin compose keeps a chinese patient report from the draft', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::Mandarin,
    ]);

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal']],
        'overall_confidence' => 0.9,
        'citations' => [],
        'medgemma_draft' => "FINDINGS:\n右下肺斑片影。\n\nIMPRESSION:\n考虑感染，请结合临床。",
        'patient_report' => [
            'summary' => '扫描显示右下肺有一片阴影，需要医生判断。',
            'what_this_means' => '这可能是感染，不是最终诊断。',
            'questions_for_doctor' => ['我需要进一步治疗吗？'],
            'action_plan' => ['把结果带给医生'],
        ],
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['patient_report']['summary'])->toContain('医生')
        ->and($reports['physician_report']['impression'])->toContain('结合临床');
});

test('tamil compose keeps a tamil patient report from the draft', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::Tamil,
    ]);

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal']],
        'overall_confidence' => 0.9,
        'citations' => [],
        'medgemma_draft' => "FINDINGS:\nவலது கீழ் நுரையீரலில் ஒளிபுகா தன்மை.\n\nIMPRESSION:\nமருத்துவர் மறுஆய்வு தேவை.",
        'patient_report' => [
            'summary' => 'ஸ்கேனில் ஒரு பகுதி தெரிகிறது; மருத்துவர் விளக்குவார்.',
            'what_this_means' => 'இது நோயறிவு அல்ல.',
            'questions_for_doctor' => ['மேலும் சிகிச்சை தேவையா?'],
            'action_plan' => ['மருத்துவரிடம் காட்டவும்'],
        ],
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['patient_report']['summary'])->toContain('மருத்துவர்')
        ->and($reports['physician_report']['impression'])->toContain('மருத்துவர்');
});
