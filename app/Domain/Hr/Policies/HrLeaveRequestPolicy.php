<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrLeaveRequest;
use App\Models\User;

class HrLeaveRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.leave.viewAny');
    }

    public function view(User $user, HrLeaveRequest $leaveRequest): bool
    {
        return $user->canDo('hr.leave.viewAny')
            || $user->id === $leaveRequest->user_id;
    }

    public function create(User $user): bool
    {
        return $user->canDo('hr.leave.manage') || true;
    }

    public function update(User $user, HrLeaveRequest $leaveRequest): bool
    {
        return $user->canDo('hr.leave.manage');
    }

    public function approve(User $user, HrLeaveRequest $leaveRequest): bool
    {
        return $user->canDo('hr.leave.approve');
    }

    public function decline(User $user, HrLeaveRequest $leaveRequest): bool
    {
        return $user->canDo('hr.leave.approve');
    }
}
