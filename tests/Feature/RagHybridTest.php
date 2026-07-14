<?php

use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\RagService;

test('hybrid rag returns diversified citations without static stubs on weak retrieve', function () {
    $rag = app(RagService::class);
    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create(['user_id' => $user->id]);

    GuidelineChunk::create([
        'source' => 'MOH CPG A',
        'section' => '1',
        'content' => 'pneumonia consolidation antibiotic therapy chest radiograph',
        'embedding' => $rag->localHashEmbed('pneumonia consolidation antibiotic therapy chest radiograph'),
    ]);
    GuidelineChunk::create([
        'source' => 'MOH CPG B',
        'section' => '2',
        'content' => 'pneumonia consolidation antibiotic therapy chest radiograph secondary',
        'embedding' => $rag->localHashEmbed('pneumonia consolidation antibiotic therapy chest radiograph secondary'),
    ]);
    GuidelineChunk::create([
        'source' => 'MOH CPG C',
        'section' => '3',
        'content' => 'tuberculosis cavitation sputum culture',
        'embedding' => $rag->localHashEmbed('tuberculosis cavitation sputum culture'),
    ]);

    $citations = $rag->retrieveCitations($record, [
        ['label' => 'pneumonia consolidation'],
    ]);

    expect($citations)->not->toBeEmpty()
        ->and(collect($citations)->pluck('source')->unique()->count())->toBeGreaterThan(0);

    $weak = $rag->retrieveCitations($record, [
        ['label' => 'zzzz-unrelated-token-qqqq'],
    ]);

    expect($rag->wasWeakRetrieval())->toBeTrue()
        ->and($weak)->toBeEmpty();
});
