<?php

namespace App\Policies\Roadmap;

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
        return $user->canDo('roadmap.view') && $this->sameTenant($user, $plan->tenant_id);
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

        return $user->canDo('roadmap.manage') && $this->sameTenant($user, $plan->tenant_id);
    }

    public function approve(User $user, QuarterlyRoadmapPlan $plan): bool
    {
        return $user->canDo('roadmap.approve') && $this->sameTenant($user, $plan->tenant_id);
    }

    public function publish(User $user, QuarterlyRoadmapPlan $plan): bool
    {
        return $user->canDo('roadmap.approve') && $this->sameTenant($user, $plan->tenant_id);
    }

    protected function sameTenant(User $user, ?int $tenantId): bool
    {
        if ($tenantId === null || $user->organization_id === null) {
            return false;
        }

        return (int) $user->organization_id === (int) $tenantId;
    }
}
