<?php

use App\Models\User;

test('voice triage returns structured urgency from transcript', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(route('voice.triage.transcribe'), [
        'transcript' => 'I have chest pain and mild fever for two days',
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('triage.urgency', 'urgent')
        ->assertJsonStructure([
            'transcript',
            'triage' => ['urgency', 'chief_complaint', 'suggested_questions', 'disclaimer'],
        ]);
});

test('voice triage requires audio or transcript', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('voice.triage.transcribe'), [])
        ->assertUnprocessable();
});
