<?php

use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\RagService;

test('rag retrieves relevant guideline chunks by embedding similarity', function () {
    $rag = app(RagService::class);

    GuidelineChunk::create([
        'source' => 'MOH Malaysia CPG - Community Acquired Pneumonia',
        'section' => '4.2 Diagnosis',
        'content' => 'Chest radiograph may show lobar or patchy consolidation related to pneumonia.',
        'embedding' => $rag->localHashEmbed('chest radiograph pneumonia consolidation opacity'),
    ]);

    GuidelineChunk::create([
        'source' => 'MOH Malaysia CPG - Thalassemia',
        'section' => '2.1 Laboratory',
        'content' => 'Microcytic hypochromic anemia with elevated HbA2 suggests beta-thalassemia trait.',
        'embedding' => $rag->localHashEmbed('hemoglobin anemia thalassemia laboratory'),
    ]);

    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create(['user_id' => $user->id]);

    $citations = $rag->retrieveCitations($record, [
        ['label' => 'Right lower lobe opacity', 'severity' => 'abnormal'],
    ]);

    expect($citations)->not->toBeEmpty()
        ->and($citations[0]['source'])->toContain('Pneumonia');
});
