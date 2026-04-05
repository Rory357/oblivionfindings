<?php

namespace App\Policies;

use App\Models\ClientMedication;
use App\Models\User;

class ClientMedicationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('medications.view');
    }

    public function view(User $user, ClientMedication $medication): bool
    {
        return $user->canDo('medications.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('medications.orders.manage');
    }

    public function update(User $user, ClientMedication $medication): bool
    {
        return $user->canDo('medications.orders.manage');
    }

    public function delete(User $user, ClientMedication $medication): bool
    {
        // Audit-critical: medications must not be deleted.
        return false;
    }
}
