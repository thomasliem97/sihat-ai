<?php

namespace Database\Factories;

use App\Models\GuidelineChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuidelineChunk>
 */
class GuidelineChunkFactory extends Factory
{
    protected $model = GuidelineChunk::class;

    public function definition(): array
    {
        return [
            'source' => 'MOH Malaysia CPG',
            'section' => fake()->word(),
            'content' => fake()->paragraph(),
        ];
    }
}
