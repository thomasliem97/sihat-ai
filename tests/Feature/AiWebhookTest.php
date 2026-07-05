<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\AnalysisJob;
use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\RagService;

beforeEach(function () {
    config(['services.sihat_ai.webhook_secret' => 'test-secret']);
});

test('webhook rejects invalid signature', function () {
    $response = $this->postJson(route('ai.webhook'), [
        'job_id' => 'missing',
        'status' => 'completed',
    ], [
        'X-Sihat-Signature' => 'bad',
    ]);

    $response->assertUnauthorized();
});

test('webhook completes analysis with valid signature', function () {
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
        'external_job_id' => 'job-abc-123',
    ]);

    $payload = [
        'job_id' => 'job-abc-123',
        'status' => 'completed',
        'detected_modality' => 'xray',
        'route_confidence' => 0.9,
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
            'differential_diagnosis' => [
                ['condition' => 'Community-acquired pneumonia', 'confidence' => 0.7],
            ],
            'bounding_boxes' => [],
        ],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $raw, 'test-secret');

    $response = $this->call(
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
    );

    $response->assertSuccessful();

    $record->refresh();
    $job->refresh();

    expect($record->status)->toBe(RecordStatus::Completed)
        ->and($record->physician_report)->not->toBeNull()
        ->and($record->patient_report)->not->toBeNull()
        ->and($record->citations)->not->toBeEmpty()
        ->and($record->guardrail_flags)->toContain('confidence_publish')
        ->and($job->status)->toBe('completed');
});

test('webhook failure marks record failed', function () {
    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'status' => RecordStatus::Processing,
    ]);
    AnalysisJob::factory()->create([
        'medical_record_id' => $record->id,
        'status' => 'running',
        'external_job_id' => 'fail-job',
    ]);

    $payload = [
        'job_id' => 'fail-job',
        'status' => 'failed',
        'error' => 'GPU timeout',
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

    expect($record->fresh()->status)->toBe(RecordStatus::Failed)
        ->and($record->fresh()->error_message)->toBe('GPU timeout');
});
