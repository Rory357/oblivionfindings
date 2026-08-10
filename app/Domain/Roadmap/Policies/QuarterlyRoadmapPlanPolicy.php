<?php

namespace App\Domain\Roadmap\Policies;

use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Models\User;

class QuarterlyRoadmapPlanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function view(User $user, QuarterlyRoadmapPlan $plan): bool
    {
        return $user->canDo('roadmap.view');
    }

    public function create(User $user): bool
    {
        return $user->canDo('roadmap.manage');
    }

    public function update(User $user, QuarterlyRoadmapPlan $plan): bool
    {
        if ($plan->isPublished()) {
            return false;
        }

        return $user->canDo('roadmap.manage');
    }

    public function publish(User $user, QuarterlyRoadmapPlan $plan): bool
    {
        return $user->canDo('roadmap.approve');
    }
}
