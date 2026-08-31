<?php

use App\Models\GuidelineChunk;
use App\Models\MedicalRecord;
use App\Models\User;
use App\Services\RagService;
use App\Support\GuidelineIngestor;
use Illuminate\Support\Facades\File;

test('ingestor stores official qr text and rag retrieves tuberculosis for a cxr query', function () {
    config(['services.openai.api_key' => '']);
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sihat-qr-'.uniqid();
    File::ensureDirectoryExists($dir);

    File::put($dir.DIRECTORY_SEPARATOR.'QR_Management_of_Tuberculosis_4th_Edition.txt', <<<'TXT'
QUICK REFERENCE FOR HEALTHCARE PROVIDERS MANAGEMENT OF TUBERCULOSIS (FOURTH EDITION)

## Key Messages

1. The vision of the Malaysian Tuberculosis (TB) Control Programme is for Malaysia to be a TB-free country by 2035.
2. Adults with productive cough, haemoptysis, loss of appetite, unexplained weight loss, fever, night sweats and fatigue should be screened for pulmonary TB (PTB).
3. Testing with Xpert Ultra and mycobacterial culture should be done as part of assessment for smear negative and extrapulmonary tuberculosis. Chest radiograph (CXR) should be done in people with suspected EPTB to rule out concomitant PTB.
TXT);

    File::put($dir.DIRECTORY_SEPARATOR.'QR-Thalassaemia_Second_Edition.txt', <<<'TXT'
QUICK REFERENCE FOR HEALTHCARE PROVIDERS MANAGEMENT OF THALASSAEMIA (SECOND EDITION)

## Key Messages

1. Thalassaemia is a group of hereditary haemoglobin (Hb) disorders characterised by decreased or absent synthesis of normal globin chains.
2. Diagnosis of thalassaemia is made by screening with full blood count (FBC) and Hb typing, followed by confirmation with molecular DNA analysis when indicated.
TXT);

    $count = app(GuidelineIngestor::class)->ingest($dir);

    expect($count)->toBeGreaterThanOrEqual(2)
        ->and(GuidelineChunk::query()->where('source', 'like', '%Tuberculosis%')->exists())->toBeTrue()
        ->and(GuidelineChunk::query()->where('source', 'like', '%Community Acquired%')->exists())->toBeFalse();

    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'title' => 'Chest X-ray, cough 2 weeks',
    ]);

    $tb = GuidelineChunk::query()->where('source', 'like', '%Tuberculosis%')->first();
    expect($tb)->not->toBeNull();

    $citations = app(RagService::class)->retrieveCitations($record, [
        ['label' => 'Right lower lobe opacity', 'description' => 'Patchy airspace opacity'],
    ], ['Pulmonary tuberculosis']);

    expect($citations)->not->toBeEmpty()
        ->and($citations[0]['source'])->toContain('Tuberculosis');

    File::deleteDirectory($dir);
});

test('official qr corpus ingests without invented guideline titles', function () {
    config(['services.openai.api_key' => '']);
    $dir = storage_path('guidelines/text');
    $files = is_dir($dir) ? glob($dir.DIRECTORY_SEPARATOR.'QR*.txt') ?: [] : [];

    if (count($files) < 10) {
        $this->markTestSkipped('Official QR text corpus is not on disk.');
    }

    $count = app(GuidelineIngestor::class)->ingest($dir);

    expect($count)->toBeGreaterThan(50)
        ->and(GuidelineChunk::query()->where('source', 'like', '%Community Acquired Pneumonia%')->count())->toBe(0)
        ->and(GuidelineChunk::query()->where('source', 'like', 'MOH QR -%')->count())->toBe($count);
});

test('ingestor splits a long paragraph so mysql text columns can store it', function () {
    config(['services.openai.api_key' => '']);
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sihat-qr-'.uniqid();
    File::ensureDirectoryExists($dir);

    File::put(
        $dir.DIRECTORY_SEPARATOR.'QR_Management_of_Tuberculosis_4th_Edition.txt',
        str_repeat('Chest radiograph should be done in people with suspected pulmonary tuberculosis. ', 900),
    );

    $count = app(GuidelineIngestor::class)->ingest($dir);

    expect($count)->toBeGreaterThan(10);
    GuidelineChunk::query()->each(fn (GuidelineChunk $chunk) => expect(mb_strlen($chunk->content))->toBeLessThanOrEqual(1100));

    File::deleteDirectory($dir);
});
