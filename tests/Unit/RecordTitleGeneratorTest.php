<?php

use App\Enums\Modality;
use App\Models\MedicalRecord;
use App\Services\RecordTitleGenerator;
use Illuminate\Support\Facades\Http;

test('fromFilename uses the stem and falls back when empty', function () {
    expect(RecordTitleGenerator::fromFilename('cbc_panel.PDF'))->toBe('cbc panel')
        ->and(RecordTitleGenerator::fromFilename('.pdf'))->toBe('Untitled record');
});

test('suggest returns null unless the title was left blank', function () {
    Http::fake();

    $record = new MedicalRecord([
        'title' => 'Chest X-ray',
        'title_generated' => false,
        'modality' => Modality::Xray,
        'original_filename' => 'scan.jpg',
    ]);

    expect(app(RecordTitleGenerator::class)->suggest($record, [
        'findings' => [['label' => 'Right lower zone opacity']],
    ]))->toBeNull();

    Http::assertNothingSent();
});

test('suggest asks openai for a short title when the field was blank', function () {
    config(['services.openai.api_key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response([
            'output_text' => json_encode(['title' => 'Chest X-ray, right lower zone opacity'], JSON_THROW_ON_ERROR),
        ], 200),
    ]);

    $record = new MedicalRecord([
        'title' => 'scan',
        'title_generated' => true,
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'original_filename' => 'scan.jpg',
    ]);

    $title = app(RecordTitleGenerator::class)->suggest($record, [
        'findings' => [['label' => 'Right lower zone opacity']],
        'impression' => 'Correlate for infection.',
    ]);

    expect($title)->toBe('Chest X-ray, right lower zone opacity');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return str_contains($request->url(), '/v1/responses')
            && ($body['text']['format']['name'] ?? null) === 'record_title'
            && str_contains((string) json_encode($body), 'Right lower zone opacity');
    });
});
