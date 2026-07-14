<?php

namespace Database\Factories;

use App\Enums\ClinicalFlag;
use App\Models\Biomarker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Biomarker>
 */
class BiomarkerFactory extends Factory
{
    protected $model = Biomarker::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Hemoglobin',
            'value' => fake()->randomFloat(1, 8, 16),
            'unit' => 'g/dL',
            'reference_low' => 12.0,
            'reference_high' => 16.0,
            'status' => ClinicalFlag::Normal,
            'collected_at' => now(),
        ];
    }
}
