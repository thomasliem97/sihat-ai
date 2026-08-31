<?php

use App\Enums\Modality;
use App\Models\MedicalRecord;
use App\Services\LabStructurer;
use Illuminate\Support\Facades\Http;

test('structures a lab draft through the responses api', function () {
    config(['services.openai.api_key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode([
                'findings' => [
                    [
                        'label' => 'Haemoglobin',
                        'value' => '8.1',
                        'unit' => 'g/dL',
                        'reference' => '11.5-16.5',
                        'severity' => 'abnormal',
                        'confidence' => 0.9,
                        'description' => 'Haemoglobin 8.1 g/dL is below the printed interval 11.5-16.5.',
                    ],
                ],
                'biomarkers' => [
                    [
                        'name' => 'Haemoglobin',
                        'value' => '8.1',
                        'unit' => 'g/dL',
                        'reference_low' => '11.5',
                        'reference_high' => '16.5',
                        'status' => 'abnormal',
                    ],
                ],
                'differential_diagnosis' => [
                    ['condition' => 'Iron deficiency anaemia', 'confidence' => 0.4],
                ],
                'overall_confidence' => 0.86,
                'recommendations' => ['Correlate with MCV and ferritin.'],
                'patient_report' => [
                    'summary' => 'Haemoglobin is low.',
                    'what_this_means' => 'This can be anaemia; your doctor will decide.',
                    'questions_for_doctor' => ['Do I need iron studies?'],
                    'action_plan' => ['Discuss this result with your doctor.'],
                ],
                'bounding_boxes' => [],
            ], JSON_THROW_ON_ERROR),
        ], 200),
    ]);

    $decoded = app(LabStructurer::class)->structure(
        "FINDINGS:\nHaemoglobin 8.1 g/dL (11.5-16.5) L.\n\nIMPRESSION:\nAnaemia; correlate with MCV.",
        'en',
    );

    expect($decoded['biomarkers'][0]['name'])->toBe('Haemoglobin')
        ->and($decoded['findings'][0]['severity'])->toBe('abnormal');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/v1/responses')) {
            return false;
        }
        $body = $request->data();

        return ($body['reasoning']['effort'] ?? null) === 'low'
            && ($body['text']['format']['name'] ?? null) === 'lab_result';
    });
});

test('structures from the printout not invented draft analytes', function () {
    config(['services.openai.api_key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode([
                'findings' => [],
                'biomarkers' => [],
                'differential_diagnosis' => [],
                'overall_confidence' => 0.5,
                'recommendations' => [],
                'patient_report' => [
                    'summary' => '',
                    'what_this_means' => '',
                    'questions_for_doctor' => [],
                    'action_plan' => [],
                ],
                'bounding_boxes' => [],
            ], JSON_THROW_ON_ERROR),
        ], 200),
    ]);

    app(LabStructurer::class)->structure(
        "FINDINGS:\nCreatinine 85 (assume within normal limits).\n\nIMPRESSION:\nCheck ANA.",
        'en',
        "ESR  40  mm/hr  0-20  H\nHaemoglobin  13.5  g/dL  11.5-15.0\n",
    );

    Http::assertSent(function ($request) {
        $payload = json_encode($request->data());

        return str_contains((string) $payload, 'PRINTOUT TEXT')
            && str_contains((string) $payload, 'ESR')
            && str_contains((string) $payload, 'ignore analytes the draft invented');
    });
});

test('skips structuring when biomarkers are already present', function () {
    Http::fake();

    $record = MedicalRecord::factory()->make([
        'modality' => Modality::LabPdf,
        'detected_modality' => Modality::LabPdf,
        'user_id' => 1,
    ]);

    $incoming = [
        'medgemma_draft' => "FINDINGS:\nK 6.8\n\nIMPRESSION:\nHyperkalaemia.",
        'biomarkers' => [
            ['name' => 'Potassium', 'value' => '6.8', 'unit' => 'mmol/L', 'status' => 'critical'],
        ],
    ];

    $out = app(LabStructurer::class)->mergeIfNeeded($record, $incoming);

    expect($out['biomarkers'][0]['name'])->toBe('Potassium');
    Http::assertNothingSent();
});
