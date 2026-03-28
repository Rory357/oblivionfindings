<?php

namespace App\Domain\Hr\Policies;

use App\Domain\Hr\Models\HrPerformanceReview;
use App\Models\User;

class HrPerformanceReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('hr.performance.view');
    }

    public function view(User $user, HrPerformanceReview $review): bool
    {
        return $user->canDo('hr.performance.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('hr.performance.manage');
    }

    public function update(User $user, HrPerformanceReview $review): bool
    {
        return $user->canDo('hr.performance.manage');
    }
}
