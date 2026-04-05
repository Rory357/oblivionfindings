<?php

namespace App\Policies;

use App\Models\ClientControlledDrugEntry;
use App\Models\User;

class ClientControlledDrugEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('medications.controlled.view');
    }

    public function view(User $user, ClientControlledDrugEntry $entry): bool
    {
        return $user->canDo('medications.controlled.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('medications.controlled.record');
    }

    public function update(User $user, ClientControlledDrugEntry $entry): bool
    {
        return $user->canDo('medications.controlled.record');
    }

    public function delete(User $user, ClientControlledDrugEntry $entry): bool
    {
        // Audit-critical: controlled drug entries must not be deleted.
        return false;
    }
}
