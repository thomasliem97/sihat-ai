<?php

namespace App\Policies;

use App\Enums\TriageSessionStatus;
use App\Models\TriageSession;
use App\Models\User;

class TriageSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TriageSession $triageSession): bool
    {
        if ($triageSession->user_id === $user->id) {
            return true;
        }

        return $user->isPhysician()
            && $triageSession->shared_at !== null;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, TriageSession $triageSession): bool
    {
        return $triageSession->user_id === $user->id;
    }

    public function message(User $user, TriageSession $triageSession): bool
    {
        return $triageSession->user_id === $user->id
            && $triageSession->status === TriageSessionStatus::Active;
    }

    public function share(User $user, TriageSession $triageSession): bool
    {
        return $triageSession->user_id === $user->id
            && $user->isPatient()
            && $triageSession->status === TriageSessionStatus::Active;
    }

    public function archive(User $user, TriageSession $triageSession): bool
    {
        return $triageSession->user_id === $user->id
            && $triageSession->status === TriageSessionStatus::Active;
    }
}
