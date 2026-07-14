<?php

namespace Database\Factories;

use App\Enums\Modality;
use App\Enums\RecordStatus;
use App\Enums\ReportLanguage;
use App\Models\MedicalRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MedicalRecord>
 */
class MedicalRecordFactory extends Factory
{
    protected $model = MedicalRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'modality' => Modality::Xray,
            'status' => RecordStatus::Pending,
            'file_path' => 'medical-records/'.fake()->uuid().'.jpg',
            'original_filename' => 'scan.jpg',
            'mime_type' => 'image/jpeg',
            'language' => ReportLanguage::English,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => RecordStatus::Completed,
            'overall_confidence' => 0.85,
            'analyzed_at' => now(),
        ]);
    }
}
