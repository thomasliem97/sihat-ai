<?php

namespace Database\Factories;

use App\Enums\ExplainerMessageRole;
use App\Models\MedicalRecord;
use App\Models\RecordExplainerMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecordExplainerMessage>
 */
class RecordExplainerMessageFactory extends Factory
{
    protected $model = RecordExplainerMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medical_record_id' => MedicalRecord::factory(),
            'user_id' => User::factory(),
            'role' => ExplainerMessageRole::User,
            'content' => fake()->sentence(),
            'finding_index' => null,
            'selected_box' => null,
        ];
    }
}
