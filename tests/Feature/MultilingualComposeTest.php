<?php

use App\Enums\ReportLanguage;
use App\Models\MedicalRecord;
use App\Services\AiPipelineService;

test('mandarin compose returns chinese patient summary', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::Mandarin,
    ]);

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal']],
        'overall_confidence' => 0.9,
        'citations' => [],
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['patient_report']['summary'])->toContain('医生')
        ->and($reports['physician_report']['summary'])->toContain('主要发现');
});

test('tamil compose returns tamil patient summary', function () {
    $record = MedicalRecord::factory()->create([
        'language' => ReportLanguage::Tamil,
    ]);

    $reports = app(AiPipelineService::class)->composeReports($record, [
        'findings' => [['label' => 'Opacity', 'severity' => 'abnormal']],
        'overall_confidence' => 0.9,
        'citations' => [],
    ], ['confidence_publish', 'medical_disclaimer_required', 'not_a_diagnosis']);

    expect($reports['patient_report']['summary'])->toContain('மருத்துவர்')
        ->and($reports['physician_report']['summary'])->toContain('முக்கிய');
});
