<?php

use App\Models\MedicalRecord;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('physicians are redirected to physician dashboard', function () {
    $user = User::factory()->physician()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('physician.dashboard'));
});

test('patients are redirected to patient dashboard', function () {
    $user = User::factory()->patient()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('patient.dashboard'));
});

test('physician can view records index', function () {
    $user = User::factory()->physician()->create();
    $this->actingAs($user);

    $response = $this->get(route('records.index'));
    $response->assertOk();
});

test('patient cannot access physician dashboard', function () {
    $user = User::factory()->patient()->create();
    $this->actingAs($user);

    $response = $this->get(route('physician.dashboard'));
    $response->assertForbidden();
});

test('patient can view own record', function () {
    $patient = User::factory()->patient()->create();
    $record = MedicalRecord::factory()->create(['user_id' => $patient->id]);

    $this->actingAs($patient);

    $response = $this->get(route('records.show', $record));
    $response->assertOk();
});

test('patient cannot view another patients record', function () {
    $patient = User::factory()->patient()->create();
    $other = User::factory()->patient()->create();
    $record = MedicalRecord::factory()->create(['user_id' => $other->id]);

    $this->actingAs($patient);

    $response = $this->get(route('records.show', $record));
    $response->assertForbidden();
});
