<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrComplianceMatrix;
use App\Models\User;

class HrComplianceMatrixPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.compliance.view');
    }

    public function view(User $user, HrComplianceMatrix $matrix): bool
    {
        return $user->canDo('hr.compliance.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('hr.compliance.manage');
    }

    public function update(User $user, HrComplianceMatrix $matrix): bool
    {
        return $user->canDo('hr.compliance.manage');
    }
}
