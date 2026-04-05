<?php

namespace App\Services\Operations;

use App\Models\CarePlan;
use App\Models\User;

class CarePlanService
{
    public function getReviewsDue(int $organizationId, int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return CarePlan::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('next_review_at')
            ->where('next_review_at', '<=', now()->addDays($days))
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->orderBy('next_review_at')
            ->get();
    }

    public function createNewVersion(CarePlan $carePlan, User $reviewer): CarePlan
    {
        $newVersion = $carePlan->replicate();
        $newVersion->parent_id = $carePlan->id;
        $newVersion->version = ($carePlan->version ?? 1) + 1;
        $newVersion->status = 'draft';
        $newVersion->reviewed_at = null;
        $newVersion->reviewed_by = null;
        $newVersion->created_by = $reviewer->id;
        $newVersion->save();

        // Copy goals
        foreach ($carePlan->goals as $goal) {
            $newGoal = $goal->replicate();
            $newGoal->care_plan_id = $newVersion->id;
            $newGoal->save();
        }

        return $newVersion;
    }

    public function completeReview(CarePlan $carePlan, User $reviewer): void
    {
        if ($carePlan->goals()->count() === 0) {
            throw new \DomainException('Cannot activate a care plan without at least one goal.');
        }

        $carePlan->update([
            'status' => 'active',
            'reviewed_at' => now(),
            'reviewed_by' => $reviewer->id,
            'next_review_at' => now()->addMonths(6)->toDateString(),
        ]);
    }

    public function getExpiredPlans(int $organizationId): \Illuminate\Database\Eloquent\Collection
    {
        return CarePlan::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', now())
            ->with(['client:id,first_name,last_name'])
            ->get();
    }
}
