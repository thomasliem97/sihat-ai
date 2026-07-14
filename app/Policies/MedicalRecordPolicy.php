<?php

namespace App\Policies;

use App\Models\MedicalRecord;
use App\Models\User;

class MedicalRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MedicalRecord $medicalRecord): bool
    {
        if ($user->isPhysician()) {
            return true;
        }

        return $medicalRecord->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MedicalRecord $medicalRecord): bool
    {
        return $user->isPhysician() || $medicalRecord->user_id === $user->id;
    }

    public function updateReport(User $user, MedicalRecord $medicalRecord): bool
    {
        return $user->isPhysician() && ! $medicalRecord->isSigned();
    }

    public function sign(User $user, MedicalRecord $medicalRecord): bool
    {
        return $user->isPhysician();
    }

    public function delete(User $user, MedicalRecord $medicalRecord): bool
    {
        return $user->isPhysician() || $medicalRecord->user_id === $user->id;
    }
}
