<?php

namespace Database\Factories;

use App\Enums\TriageRoleContext;
use App\Enums\TriageSessionStatus;
use App\Models\TriageSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriageSession>
 */
class TriageSessionFactory extends Factory
{
    protected $model = TriageSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject_user_id' => null,
            'role_context' => TriageRoleContext::Patient,
            'locale' => '',
            'status' => TriageSessionStatus::Active,
            'urgency' => null,
            'chief_complaint' => null,
            'summary' => null,
            'shared_at' => null,
        ];
    }

    public function physician(): static
    {
        return $this->state(fn () => [
            'role_context' => TriageRoleContext::Physician,
        ]);
    }

    public function shared(): static
    {
        return $this->state(fn () => [
            'shared_at' => now(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => TriageSessionStatus::Archived,
        ]);
    }
}
