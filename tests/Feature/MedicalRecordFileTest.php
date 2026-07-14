<?php

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
