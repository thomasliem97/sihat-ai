<?php

namespace Database\Factories;

use App\Enums\TriageInputModality;
use App\Enums\TriageMessageRole;
use App\Models\TriageMessage;
use App\Models\TriageSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriageMessage>
 */
class TriageMessageFactory extends Factory
{
    protected $model = TriageMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'triage_session_id' => TriageSession::factory(),
            'role' => TriageMessageRole::User,
            'content' => fake()->sentence(),
            'input_modality' => TriageInputModality::Text,
            'stt_engine' => null,
        ];
    }
}
