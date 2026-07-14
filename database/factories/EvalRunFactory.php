<?php

namespace Database\Factories;

use App\Models\EvalRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EvalRun>
 */
class EvalRunFactory extends Factory
{
    protected $model = EvalRun::class;

    public function definition(): array
    {
        return [
            'run_type' => 'medqa',
            'sample_count' => 100,
            'avg_score' => fake()->randomFloat(1, 60, 90),
            'metrics' => [],
        ];
    }
}
