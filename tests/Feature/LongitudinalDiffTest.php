<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;

test('longitudinal diff marks new imaging findings against prior record', function () {
    $user = User::factory()->create();
    $prior = MedicalRecord::factory()->completed()->create([
        'user_id' => $user->id,
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'findings' => [
            ['label' => 'Cardiomegaly', 'severity' => 'borderline'],
        ],
        'analyzed_at' => now()->subMonth(),
    ]);

    $current = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'status' => RecordStatus::Processing,
    ]);

    $diff = app(AiPipelineService::class)->buildLongitudinalDiff($current, [
        'findings' => [
            ['label' => 'Cardiomegaly', 'severity' => 'borderline'],
            ['label' => 'Right lower lobe opacity', 'severity' => 'abnormal'],
        ],
    ]);

    expect($diff['has_prior'])->toBeTrue()
        ->and($diff['prior_record_id'])->toBe($prior->id)
        ->and(collect($diff['changes'])->pluck('change'))->toContain('new')
        ->and(collect($diff['changes'])->pluck('change'))->toContain('stable');
});

test('longitudinal lab diff compares biomarker values to prior', function () {
    $user = User::factory()->create();
    $prior = MedicalRecord::factory()->completed()->create([
        'user_id' => $user->id,
        'modality' => Modality::LabPdf,
        'detected_modality' => Modality::LabPdf,
        'analyzed_at' => now()->subWeek(),
    ]);
    $prior->biomarkers()->create([
        'user_id' => $user->id,
        'name' => 'Hemoglobin',
        'value' => 11.0,
        'unit' => 'g/dL',
        'status' => 'borderline',
        'collected_at' => now()->subWeek(),
    ]);

    $current = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'modality' => Modality::LabPdf,
        'detected_modality' => Modality::LabPdf,
    ]);

    $diff = app(AiPipelineService::class)->buildLongitudinalDiff($current, [
        'biomarkers' => [
            ['name' => 'Hemoglobin', 'value' => 13.5, 'unit' => 'g/dL'],
        ],
    ]);

    expect($diff['has_prior'])->toBeTrue()
        ->and($diff['changes'][0]['change'])->toBe('worse');
});
