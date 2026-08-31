<?php

use App\Enums\Modality;
use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\RagService;

test('embed without an api key returns null', function () {
    config(['services.openai.api_key' => '']);

    expect(app(RagService::class)->embed('pneumonia consolidation'))->toBeNull();
});

test('retrieve uses bm25 when openai is unavailable', function () {
    config(['services.openai.api_key' => '']);

    $rag = app(RagService::class);
    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Tuberculosis 4th Edition',
        'section' => 'Key messages',
        'content' => 'Adults with productive cough should be screened for pulmonary TB. Chest radiograph should be done in people with suspected EPTB to rule out concomitant PTB.',
        'embedding' => null,
    ]);

    $record = MedicalRecord::factory()->create([
        'user_id' => User::factory()->create()->id,
        'title' => 'zzz-visit',
        'modality' => Modality::Unknown,
        'detected_modality' => Modality::Unknown,
    ]);

    $citations = $rag->retrieveCitations($record, [
        ['label' => 'chest radiograph', 'description' => 'productive cough pulmonary tuberculosis'],
    ]);

    expect($citations)->not->toBeEmpty()
        ->and($citations[0]['source'])->toContain('Tuberculosis')
        ->and($citations[0]['excerpt'])->toContain('Chest radiograph')
        ->and($rag->wasWeakRetrieval())->toBeFalse();
});

test('rag retrieves relevant guideline chunks by embedding similarity', function () {
    $rag = app(RagService::class);
    $near = array_fill(0, 8, 0.0);
    $near[0] = 1.0;
    $far = array_fill(0, 8, 0.0);
    $far[7] = 1.0;
    fakeOpenAiEmbedding($near);

    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Tuberculosis 4th Edition',
        'section' => 'Key messages',
        'content' => 'Adults with productive cough should be screened for pulmonary TB. Chest radiograph should be done in people with suspected EPTB to rule out concomitant PTB.',
        'embedding' => $near,
    ]);

    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Thalassaemia Second Edition',
        'section' => 'Key messages',
        'content' => 'Diagnosis of thalassaemia is made by screening with full blood count and Hb typing.',
        'embedding' => $far,
    ]);

    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create(['user_id' => $user->id]);

    $citations = $rag->retrieveCitations($record, [
        ['label' => 'pulmonary tuberculosis chest radiograph', 'severity' => 'abnormal'],
    ]);

    expect($citations)->not->toBeEmpty()
        ->and($citations[0]['source'])->toContain('Tuberculosis')
        ->and($citations[0]['relevance'])->toBeGreaterThanOrEqual(0.2);
});

test('rag query includes finding description and differential terms', function () {
    config(['services.openai.api_key' => '']);

    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Tuberculosis 4th Edition',
        'section' => 'Key messages',
        'content' => 'Adults with productive cough should be screened for pulmonary TB. Chest radiograph should be done in people with suspected EPTB to rule out concomitant PTB.',
        'embedding' => null,
    ]);

    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Thalassaemia Second Edition',
        'section' => 'Key messages',
        'content' => 'Diagnosis of thalassaemia is made by screening with full blood count and Hb typing.',
        'embedding' => null,
    ]);

    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'title' => 'Chest X-ray, cough 2 weeks',
    ]);

    $citations = app(RagService::class)->retrieveCitations($record, [
        ['label' => 'Opacity', 'description' => 'Patchy airspace change'],
    ], ['Pulmonary tuberculosis']);

    expect($citations)->not->toBeEmpty()
        ->and($citations[0]['source'])->toContain('Tuberculosis');
});

test('cosine similarity is zero when embedding sizes differ', function () {
    expect(app(RagService::class)->cosineSimilarity([1.0, 0.0], array_fill(0, 64, 0.1)))->toBe(0.0);
});

test('lab rag does not cite unrelated qr from the modality label', function () {
    config(['services.openai.api_key' => '']);

    GuidelineChunk::create([
        'source' => 'MOH QR - Acne 10 4 23',
        'section' => 'Quick reference',
        'content' => 'Acne vulgaris is a common skin disease of the pilosebaceous units, commonly affecting adolescents and young adults. Screening and topical retinoids.',
        'embedding' => null,
    ]);
    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Austism Spectrum Disorder in Children and Adolescents',
        'section' => 'Quick reference',
        'content' => 'Screening for ASD should be emphasised in children with high risk factors including increased parental age.',
        'embedding' => null,
    ]);

    $record = MedicalRecord::factory()->create([
        'user_id' => User::factory()->create()->id,
        'title' => 'Health screen',
        'modality' => Modality::LabPdf,
        'detected_modality' => Modality::LabPdf,
    ]);

    $rag = app(RagService::class);
    $empty = $rag->retrieveCitations($record, []);

    expect($empty)->toBeEmpty()
        ->and($rag->wasWeakRetrieval())->toBeTrue();

    $fromDump = $rag->retrieveCitations($record, [
        ['label' => 'Hepatitis screen', 'description' => 'within reference range screening'],
        ['label' => 'Haemoglobin', 'description' => 'within reference range'],
    ]);

    expect($fromDump)->toBeEmpty();

    GuidelineChunk::create([
        'source' => 'MOH QR - SLE compressed',
        'section' => 'Quick reference',
        'content' => 'Patients with active disease should be reviewed at least every 1-3 months. Raised ESR is common in SLE.',
        'embedding' => null,
    ]);

    expect($rag->retrieveCitations($record, [], ['ESR']))->toBeEmpty();

    $hep = $rag->retrieveCitations($record, [], ['Past hepatitis B infection']);
    $sources = collect($hep)->pluck('source')->implode(' ');

    expect($sources)->not->toContain('Acne')
        ->and($sources)->not->toContain('Austism');
});

test('imaging rag does not cite unrelated qr from a regional review dump', function () {
    config(['services.openai.api_key' => '']);

    GuidelineChunk::create([
        'source' => 'MOH QR - Acne 10 4 23',
        'section' => 'Quick reference',
        'content' => 'Acne vulgaris is a common skin disease. Nodules and cysts. Screening and topical retinoids.',
        'embedding' => null,
    ]);
    GuidelineChunk::create([
        'source' => 'MOH QR - Management of Austism Spectrum Disorder in Children and Adolescents',
        'section' => 'Quick reference',
        'content' => 'Screening for ASD should be emphasised in children with high risk factors including increased parental age.',
        'embedding' => null,
    ]);
    GuidelineChunk::create([
        'source' => 'MOH QR - Geriatric Hip Fracture 15 5 24',
        'section' => 'Quick reference',
        'content' => 'Plain radiographs of anterior-posterior pelvis following low energy trauma. Hip pain after a fall.',
        'embedding' => null,
    ]);

    $record = MedicalRecord::factory()->create([
        'user_id' => User::factory()->create()->id,
        'title' => 'Chest X-ray showing bilateral nodular opacities, CT recommended',
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
    ]);

    $rag = app(RagService::class);
    $citations = $rag->retrieveCitations($record, [
        [
            'label' => 'Technical quality',
            'description' => 'PA chest radiograph is adequately exposed with good inspiratory effort.',
            'severity' => 'normal',
        ],
        [
            'label' => 'Pleural spaces',
            'description' => 'The pleura is unremarkable, and the costophrenic angles are clear.',
            'severity' => 'normal',
        ],
        [
            'label' => 'Pulmonary nodular opacities',
            'description' => 'Nodular opacity in the right upper lobe.',
            'severity' => 'abnormal',
        ],
    ]);

    $sources = collect($citations)->pluck('source')->implode(' ');

    expect($sources)->not->toContain('Acne')
        ->and($sources)->not->toContain('Austism')
        ->and($sources)->not->toContain('Hip Fracture');
});
