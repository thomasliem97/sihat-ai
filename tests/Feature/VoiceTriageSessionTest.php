<?php

use App\Enums\TriageSessionStatus;
use App\Models\TriageSession;
use App\Models\User;

test('owner can archive an active triage session', function () {
    $patient = User::factory()->patient()->create();
    $session = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
    ]);

    $this->actingAs($patient)
        ->postJson(route('voice.triage.sessions.archive', $session))
        ->assertSuccessful()
        ->assertJsonPath('session.status', 'archived');

    $this->actingAs($patient)
        ->postJson(route('voice.triage.sessions.messages', $session), [
            'text' => 'Should not accept after archive',
        ])
        ->assertForbidden();
});

test('archived sessions are hidden from the owner list', function () {
    $patient = User::factory()->patient()->create();

    $active = TriageSession::factory()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'status' => TriageSessionStatus::Active,
        'chief_complaint' => 'active cough',
    ]);

    TriageSession::factory()->archived()->create([
        'user_id' => $patient->id,
        'subject_user_id' => $patient->id,
        'chief_complaint' => 'archived fever',
    ]);

    $this->actingAs($patient)
        ->get(route('voice.triage'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('voice/Triage')
            ->has('sessions', 1)
            ->where('sessions.0.id', $active->id));
});

test('physician cannot share a triage session', function () {
    $physician = User::factory()->physician()->create();
    $session = TriageSession::factory()->physician()->create([
        'user_id' => $physician->id,
        'status' => TriageSessionStatus::Active,
    ]);

    $this->actingAs($physician)
        ->postJson(route('voice.triage.sessions.share', $session))
        ->assertForbidden();
});
