<?php

namespace Database\Factories;

use App\Models\AnalysisJob;
use App\Models\MedicalRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnalysisJob>
 */
class AnalysisJobFactory extends Factory
{
    protected $model = AnalysisJob::class;

    public function definition(): array
    {
        return [
            'medical_record_id' => MedicalRecord::factory(),
            'status' => 'pending',
            'external_job_id' => (string) Str::uuid(),
        ];
    }
}
