<?php

use App\Enums\Modality;
use App\Models\AnalysisJob;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;

test('ct webhook result persists volume_meta', function () {
    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'modality' => Modality::Ct,
        'detected_modality' => Modality::Ct,
        'original_filename' => 'chest_ct_series.zip',
    ]);
    $job = AnalysisJob::factory()->create([
        'medical_record_id' => $record->id,
        'status' => 'running',
    ]);

    $pipeline = app(AiPipelineService::class);
    $result = $pipeline->completeFromWebhook($record, $job, [
        'findings' => [
            [
                'label' => 'Ground-glass opacity',
                'description' => 'Finding on mid-volume montage.',
                'confidence' => 0.81,
                'severity' => 'abnormal',
            ],
        ],
        'volume_meta' => [
            'slice_count' => 24,
            'used_slices' => [8, 9, 10, 11, 12, 13, 14, 15],
            'note' => 'Mid-slice montage (max 8)',
        ],
        'overall_confidence' => 0.81,
        'engine' => 'medgemma',
        'adapter' => 'none',
    ]);
    $pipeline->persistCompleted($record, $job, $result);

    expect($result['volume_meta'] ?? null)->toBeArray()
        ->and($result['volume_meta']['slice_count'] ?? null)->not->toBeNull()
        ->and($result['findings'])->not->toBeEmpty();

    $record->refresh();
    expect($record->volume_meta)->toBeArray()
        ->and($record->findings_embedding)->not->toBeEmpty();
});

test('histopath webhook result persists patch_meta', function () {
    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'modality' => Modality::Histopath,
        'detected_modality' => Modality::Histopath,
        'original_filename' => 'slide_histo.png',
    ]);
    $job = AnalysisJob::factory()->create([
        'medical_record_id' => $record->id,
        'status' => 'running',
    ]);

    $pipeline = app(AiPipelineService::class);
    $result = $pipeline->completeFromWebhook($record, $job, [
        'findings' => [
            [
                'label' => 'Atypical glandular architecture',
                'description' => 'Aggregated from center-region patches.',
                'confidence' => 0.79,
                'severity' => 'abnormal',
                'patch' => '1,1',
            ],
        ],
        'patch_meta' => [
            'grid' => '3x3',
            'patch_count' => 9,
            'note' => '3x3 center grid',
        ],
        'overall_confidence' => 0.78,
        'engine' => 'medgemma',
        'adapter' => 'none',
    ]);
    $pipeline->persistCompleted($record, $job, $result);

    expect($result['patch_meta'] ?? null)->toBeArray()
        ->and($result['patch_meta']['grid'] ?? null)->toBe('3x3')
        ->and($result['findings'])->not->toBeEmpty();

    $record->refresh();
    expect($record->patch_meta)->toBeArray();
});

test('filename hints route histopath and ct zip', function () {
    $pipeline = app(AiPipelineService::class);

    $histo = MedicalRecord::factory()->create([
        'modality' => Modality::Unknown,
        'original_filename' => 'breast_histopathology.png',
        'mime_type' => 'image/png',
    ]);
    $ctZip = MedicalRecord::factory()->create([
        'modality' => Modality::Unknown,
        'original_filename' => 'abdomen_ct_volume.zip',
        'mime_type' => 'application/zip',
    ]);
    $chestCt = MedicalRecord::factory()->make([
        'modality' => Modality::Unknown,
        'original_filename' => 'chest_ct_slice.png',
        'mime_type' => 'image/png',
        'file_path' => '',
    ]);

    expect($pipeline->detectModality($histo)['modality'])->toBe(Modality::Histopath)
        ->and($pipeline->detectModality($ctZip)['modality'])->toBe(Modality::Ct)
        ->and($pipeline->detectModality($chestCt)['modality'])->toBe(Modality::Ct);
});
