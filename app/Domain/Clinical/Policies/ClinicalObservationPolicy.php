<?php

namespace App\Domain\Clinical\Policies;

use App\Domain\Clinical\Models\ClinicalObservation;
use App\Models\User;

class ClinicalObservationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('clinical.observations.viewAny')
            || $user->canDo('clinical.observations.viewAssigned');
    }

    public function view(User $user, ClinicalObservation $observation): bool
    {
        return $user->canDo('clinical.observations.viewAny')
            || $user->canDo('clinical.observations.viewAssigned');
    }

    public function create(User $user): bool
    {
        return $user->canDo('clinical.observations.record')
            || $user->canDo('clinical.observations.recordClinical');
    }

    /**
     * Whether the user can record clinical-level observation types (vitals, pain).
     */
    public function recordClinical(User $user): bool
    {
        return $user->canDo('clinical.observations.recordClinical');
    }

    public function correct(User $user): bool
    {
        return $user->canDo('clinical.observations.correct');
    }
}
