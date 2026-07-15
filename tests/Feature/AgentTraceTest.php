<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\AnalysisJob;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\AiPipelineService;

beforeEach(function () {
    config(['services.modal.webhook_secret' => 'test-secret']);
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
        'hop_timings' => [
            'deidentify' => [
                'duration_ms' => 18,
                'status' => 'completed',
                'detail' => 'safe_uri sibling ready',
            ],
            'router' => [
                'duration_ms' => 4,
                'status' => 'completed',
                'detail' => 'Modality xray',
                'confidence' => 0.95,
            ],
            'analyze_started_at' => microtime(true) - 2.5,
        ],
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
        ->toContain('router', 'rag', 'guardrail', 'imaging_specialist', 'doc_specialist', 'compose')
        ->and($record->bounding_boxes)->not->toBeEmpty()
        ->and($record->longitudinal_diff)->toBeArray()
        ->and($record->physician_report['technical_notes'] ?? '')->toContain('MedGemma');

    $byHop = collect($record->agent_trace)->keyBy('hop');

    expect($byHop['router']['duration_ms'])->toBe(4)
        ->and($byHop['deidentify']['duration_ms'])->toBe(18)
        ->and($byHop['imaging_specialist']['duration_ms'])->toBeGreaterThanOrEqual(2500)
        ->and($byHop['doc_specialist']['duration_ms'])->toBeNull()
        ->and($byHop['doc_specialist']['status'])->toBe('skipped')
        ->and($byHop['rag']['duration_ms'])->not->toBeNull()
        ->and($byHop['guardrail']['duration_ms'])->not->toBeNull()
        ->and($byHop['compose']['duration_ms'])->not->toBeNull()
        ->and($byHop['merge']['duration_ms'])->not->toBeNull()
        ->and($byHop['guardrail']['detail'])->toContain('Medical disclaimer required')
        ->and($byHop['guardrail']['detail'])->toContain('Not a diagnosis')
        ->and($byHop['imaging_specialist']['detail'])->toContain('imaging finding');
});

test('guardrail hop detail is human readable', function () {
    $detail = app(AiPipelineService::class)->formatGuardrailHopDetail([
        'code' => 'ALLOW',
        'flags' => [
            'medical_disclaimer_required',
            'not_a_diagnosis',
            'confidence_publish',
        ],
    ]);

    expect($detail)->toBe(
        'Allowed to proceed. Medical disclaimer required; Not a diagnosis; Confidence high enough to publish.'
    );
});
