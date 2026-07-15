<?php

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Models\MedicalRecord;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

test('authorized users can download a medical record file', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    Storage::disk('local')->put('medical-records/demo.png', 'fake-image-bytes');

    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'uploaded_by_user_id' => $user->id,
        'file_path' => 'medical-records/demo.png',
        'original_filename' => 'demo.png',
        'mime_type' => 'image/png',
    ]);

    $this->actingAs($user)
        ->get(route('records.file', $record))
        ->assertOk();
});

test('missing medical record files return not found', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $record = MedicalRecord::factory()->create([
        'user_id' => $user->id,
        'uploaded_by_user_id' => $user->id,
        'file_path' => 'medical-records/missing.png',
        'original_filename' => 'missing.png',
        'mime_type' => 'image/png',
    ]);

    $this->actingAs($user)
        ->get(route('records.file', $record))
        ->assertNotFound();
});

test('completed imaging record exposes file_url without bounding boxes', function () {
    Storage::fake('local');

    $physician = User::factory()->physician()->create();
    Storage::disk('local')->put('medical-records/normal-pa.png', 'fake-image-bytes');

    $record = MedicalRecord::factory()->create([
        'user_id' => $physician->id,
        'uploaded_by_user_id' => $physician->id,
        'modality' => Modality::Xray,
        'detected_modality' => Modality::Xray,
        'status' => RecordStatus::Completed,
        'file_path' => 'medical-records/normal-pa.png',
        'original_filename' => 'normal-pa.png',
        'mime_type' => 'image/png',
        'findings' => [
            [
                'label' => 'No acute abnormality',
                'severity' => 'normal',
                'confidence' => 0.7,
            ],
        ],
        'bounding_boxes' => [],
        'analyzed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('records/Show')
            ->where('record.file_url', route('records.file', $record))
            ->where('record.bounding_boxes', [])
            ->where('record.modality', 'xray'));
});
