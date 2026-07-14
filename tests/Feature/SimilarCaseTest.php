<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\RagService;
use App\Services\SimilarCaseService;

test('similar case retrieval excludes self and ranks closer embeddings higher', function () {
    $rag = app(RagService::class);
    $near = $rag->localHashEmbed('right lower lobe opacity pneumonia xray');
    $far = $rag->localHashEmbed('hemoglobin anemia lab blood count');

    $anchor = MedicalRecord::factory()->completed()->create([
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'title' => 'Anchor CXR',
        'findings' => [
            ['label' => 'Right lower lobe opacity', 'severity' => 'abnormal'],
        ],
        'findings_embedding' => $near,
    ]);

    $similar = MedicalRecord::factory()->completed()->create([
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'title' => 'Similar CXR',
        'findings' => [
            ['label' => 'Right lower lobe opacity', 'severity' => 'abnormal'],
        ],
        'findings_embedding' => $near,
    ]);

    MedicalRecord::factory()->completed()->create([
        'modality' => Modality::LabPdf,
        'detected_modality' => Modality::LabPdf,
        'title' => 'Distant lab',
        'findings' => [
            ['label' => 'Hemoglobin', 'severity' => 'abnormal'],
        ],
        'findings_embedding' => $far,
    ]);

    $results = app(SimilarCaseService::class)->retrieve($anchor, 5);

    expect(collect($results)->pluck('id'))->not->toContain($anchor->id)
        ->and($results[0]['id'])->toBe($similar->id)
        ->and($results[0]['score'])->toBeGreaterThan($results[count($results) - 1]['score'] ?? 0);
});

test('physician show includes similar cases for completed records', function () {
    $physician = User::factory()->physician()->create();
    $rag = app(RagService::class);
    $emb = $rag->localHashEmbed('opacity xray');

    $current = MedicalRecord::factory()->completed()->create([
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'findings' => [['label' => 'Opacity']],
        'findings_embedding' => $emb,
        'status' => RecordStatus::Completed,
    ]);

    MedicalRecord::factory()->completed()->create([
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'findings' => [['label' => 'Opacity']],
        'findings_embedding' => $emb,
    ]);

    $this->actingAs($physician)
        ->get(route('records.show', $current))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('records/Show')
            ->has('similarCases', 1));
});
