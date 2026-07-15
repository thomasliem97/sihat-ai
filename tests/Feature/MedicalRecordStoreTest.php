<?php

use App\Models\MedicalRecord;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('physician can upload without a patient and leaves subject unset', function () {
    Storage::fake('local');
    Queue::fake();

    $physician = User::factory()->physician()->create();

    $this->actingAs($physician)
        ->post(route('records.store'), [
            'title' => 'Chest X-ray',
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])
        ->assertRedirect();

    $record = MedicalRecord::query()->first();

    expect($record)->not->toBeNull()
        ->and($record->user_id)->toBe($physician->id)
        ->and($record->uploaded_by_user_id)->toBe($physician->id)
        ->and($record->subject_user_id)->toBeNull();
});

test('physician upload with patient sets chart owner and subject', function () {
    Storage::fake('local');
    Queue::fake();

    $physician = User::factory()->physician()->create();
    $patient = User::factory()->patient()->create();

    $this->actingAs($physician)
        ->post(route('records.store'), [
            'title' => 'Chest X-ray',
            'patient_id' => $patient->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])
        ->assertRedirect();

    $record = MedicalRecord::query()->first();

    expect($record)->not->toBeNull()
        ->and($record->user_id)->toBe($patient->id)
        ->and($record->uploaded_by_user_id)->toBe($physician->id)
        ->and($record->subject_user_id)->toBe($patient->id);
});

test('physician cannot assign another physician as the patient', function () {
    Storage::fake('local');
    Queue::fake();

    $physician = User::factory()->physician()->create();
    $otherPhysician = User::factory()->physician()->create();

    $this->actingAs($physician)
        ->post(route('records.store'), [
            'title' => 'Chest X-ray',
            'patient_id' => $otherPhysician->id,
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])
        ->assertSessionHasErrors('patient_id');

    expect(MedicalRecord::query()->count())->toBe(0);
});

test('patient self upload sets subject to themselves', function () {
    Storage::fake('local');
    Queue::fake();

    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->post(route('records.store'), [
            'title' => 'Chest X-ray',
            'subject' => 'self',
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])
        ->assertRedirect();

    $record = MedicalRecord::query()->first();

    expect($record)->not->toBeNull()
        ->and($record->user_id)->toBe($patient->id)
        ->and($record->subject_user_id)->toBe($patient->id);
});

test('patient other upload leaves subject unset', function () {
    Storage::fake('local');
    Queue::fake();

    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->post(route('records.store'), [
            'title' => 'Chest X-ray',
            'subject' => 'other',
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])
        ->assertRedirect();

    $record = MedicalRecord::query()->first();

    expect($record)->not->toBeNull()
        ->and($record->user_id)->toBe($patient->id)
        ->and($record->subject_user_id)->toBeNull();
});

test('patient must choose subject self or other', function () {
    Storage::fake('local');
    Queue::fake();

    $patient = User::factory()->patient()->create();

    $this->actingAs($patient)
        ->post(route('records.store'), [
            'title' => 'Chest X-ray',
            'file' => UploadedFile::fake()->image('scan.jpg'),
        ])
        ->assertSessionHasErrors('subject');

    expect(MedicalRecord::query()->count())->toBe(0);
});
