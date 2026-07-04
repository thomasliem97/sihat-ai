<?php

use App\Enums\RecordStatus;
use App\Models\MedicalRecord;
use App\Models\User;

test('physician can update unsigned draft report', function () {
    $physician = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'status' => RecordStatus::Completed,
        'physician_report' => [
            'summary' => 'Old summary',
            'recommendations' => ['Correlate clinically'],
            'technical_notes' => 'Notes',
        ],
    ]);

    $this->actingAs($physician)
        ->patch(route('records.report.update', $record), [
            'summary' => 'Edited clinical summary',
            'recommendations' => ['Follow up in 2 weeks'],
            'technical_notes' => 'Reviewed',
        ])
        ->assertRedirect();

    $record->refresh();

    expect($record->physician_report['summary'])->toBe('Edited clinical summary')
        ->and($record->physician_report['recommendations'])->toContain('Follow up in 2 weeks');
});

test('physician can soft-sign a completed report', function () {
    $physician = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'physician_report' => [
            'summary' => 'Ready to sign',
            'recommendations' => ['Correlate clinically'],
        ],
    ]);

    $this->actingAs($physician)
        ->post(route('records.sign', $record))
        ->assertRedirect();

    $record->refresh();

    expect($record->isSigned())->toBeTrue()
        ->and($record->signed_by)->toBe($physician->id)
        ->and($record->signed_physician_report['summary'])->toBe('Ready to sign');
});

test('second sign is rejected', function () {
    $physician = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'physician_report' => ['summary' => 'Signed already'],
        'signed_physician_report' => ['summary' => 'Signed already'],
        'signed_by' => $physician->id,
        'signed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->post(route('records.sign', $record))
        ->assertStatus(422);
});

test('patient cannot sign a report', function () {
    $patient = User::factory()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'user_id' => $patient->id,
        'physician_report' => ['summary' => 'Draft'],
    ]);

    $this->actingAs($patient)
        ->post(route('records.sign', $record))
        ->assertForbidden();
});

test('signed report cannot be edited', function () {
    $physician = User::factory()->physician()->create();
    $record = MedicalRecord::factory()->completed()->create([
        'physician_report' => ['summary' => 'Locked'],
        'signed_physician_report' => ['summary' => 'Locked'],
        'signed_by' => $physician->id,
        'signed_at' => now(),
    ]);

    $this->actingAs($physician)
        ->patch(route('records.report.update', $record), [
            'summary' => 'Should fail',
        ])
        ->assertForbidden();
});
