<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\AnalysisJob;
use App\Models\MedicalRecord;
use App\Models\User;

beforeEach(function () {
    config(['services.sihat_ai.webhook_secret' => 'test-secret']);
});

test('webhook persists typed agent hop traces', function () {
    $user = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'status' => RecordStatus::Processing,
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'deidentified_at' => now(),
        'route_confidence' => 0.95,
    ]);
    AnalysisJob::factory()->create([
        'medical_record_id' => $record->id,
        'status' => 'running',
        'external_job_id' => 'trace-job-1',
    ]);

    $payload = [
        'job_id' => 'trace-job-1',
        'status' => 'completed',
        'result' => [
            'findings' => [
                [
                    'label' => 'Right lower lobe opacity',
                    'description' => 'Patchy opacity',
                    'confidence' => 0.88,
                    'severity' => 'abnormal',
                ],
            ],
            'overall_confidence' => 0.88,
            'differential_diagnosis' => [],
            'bounding_boxes' => [
                [
                    'label' => 'Right lower lobe opacity',
                    'x' => 0.5,
                    'y' => 0.5,
                    'width' => 0.2,
                    'height' => 0.2,
                    'confidence' => 0.88,
                ],
            ],
            'adapter' => 'none',
        ],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $raw, 'test-secret');

    $this->call(
        'POST',
        route('ai.webhook'),
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIHAT_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertSuccessful();

    $record->refresh();

    expect($record->agent_trace)->toBeArray()
        ->and(collect($record->agent_trace)->pluck('hop')->all())
        ->toContain('router', 'rag', 'guardrail', 'imaging', 'compose')
        ->and($record->bounding_boxes)->not->toBeEmpty()
        ->and($record->longitudinal_diff)->toBeArray()
        ->and($record->physician_report['technical_notes'] ?? '')->toContain('Adapter:');
});
