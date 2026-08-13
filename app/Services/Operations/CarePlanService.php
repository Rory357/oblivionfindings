<?php

namespace App\Services\Operations;

use App\Models\CarePlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class CarePlanService
{
    public function getReviewsDue(int $days = 30): Collection
    {
        return CarePlan::query()
            ->whereHas('client.site')
            ->where('status', 'active')
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', now()->addDays($days))
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->orderBy('next_review_at')
            ->get();
    }

    public function createNewVersion(CarePlan $carePlan, User $reviewer): CarePlan
    {
        $newVersion = $carePlan->replicate($carePlan->getHidden());
        $newVersion->parent_id = $carePlan->id;
        $newVersion->version = ($carePlan->version ?? 1) + 1;
        $newVersion->status = 'draft';
        $newVersion->reviewed_at = null;
        $newVersion->reviewed_by = null;
        $newVersion->created_by = $reviewer->id;
        $newVersion->save();

        // Copy goals
        foreach ($carePlan->goals as $goal) {
            $newGoal = $goal->replicate($goal->getHidden());
            $newGoal->care_plan_id = $newVersion->id;
            $newGoal->save();
        }

        return $newVersion;
    }

    public function getExpiredPlans(): Collection
    {
        return CarePlan::query()
            ->whereHas('client.site')
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->with(['client:id,first_name,last_name'])
            ->get();
    }
}
