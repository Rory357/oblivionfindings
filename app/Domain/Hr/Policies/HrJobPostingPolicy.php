<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrJobPosting;
use App\Models\User;

class HrJobPostingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.recruitment.view');
    }

    public function view(User $user, HrJobPosting $jobPosting): bool
    {
        return $user->canDo('hr.recruitment.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('hr.recruitment.manage');
    }

    public function update(User $user, HrJobPosting $jobPosting): bool
    {
        return $user->canDo('hr.recruitment.manage');
    }

    public function delete(User $user, HrJobPosting $jobPosting): bool
    {
        return $user->canDo('hr.recruitment.manage');
    }
}
