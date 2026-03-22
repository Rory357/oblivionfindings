<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalUpdate;
use Illuminate\Support\Facades\DB;

class GoalService
{
    /**
     * Create a new goal.
     */
    public function createGoal(array $data): HrGoal
    {
        return DB::transaction(function () use ($data) {
            return HrGoal::create([
                'tenant_id' => $data['tenant_id'],
                'user_id' => $data['user_id'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'goal_type' => $data['goal_type'],
                'category' => $data['category'] ?? null,
                'parent_goal_id' => $data['parent_goal_id'] ?? null,
                'target_value' => $data['target_value'] ?? null,
                'current_value' => $data['current_value'] ?? null,
                'unit' => $data['unit'] ?? null,
                'progress_percentage' => $data['progress_percentage'] ?? 0,
                'status' => $data['status'] ?? 'draft',
                'priority' => $data['priority'] ?? 'medium',
                'start_date' => $data['start_date'],
                'due_date' => $data['due_date'],
                'performance_review_id' => $data['performance_review_id'] ?? null,
                'created_by' => $data['created_by'],
            ]);
        });
    }

    /**
     * Update progress on a goal and log the update.
     */
    public function updateProgress(HrGoal $goal, array $data): HrGoalUpdate
    {
        return DB::transaction(function () use ($goal, $data) {
            $previousValue = $goal->current_value;
            $newValue = $data['current_value'] ?? $goal->current_value;
            $progressPercentage = $data['progress_percentage'] ?? $goal->progress_percentage;

            $update = HrGoalUpdate::create([
                'goal_id' => $goal->id,
                'user_id' => $data['user_id'],
                'previous_value' => $previousValue,
                'new_value' => $newValue,
                'progress_percentage' => $progressPercentage,
                'comment' => $data['comment'] ?? null,
            ]);

            $goalUpdate = [
                'current_value' => $newValue,
                'progress_percentage' => $progressPercentage,
            ];

            if ($progressPercentage >= 100 && $goal->status === 'active') {
                $goalUpdate['status'] = 'completed';
                $goalUpdate['completed_at'] = now();
            }

            $goal->update($goalUpdate);

            return $update;
        });
    }

    /**
     * Link a goal to a performance review.
     */
    public function linkToPerformanceReview(HrGoal $goal, int $performanceReviewId): HrGoal
    {
        return DB::transaction(function () use ($goal, $performanceReviewId) {
            $goal->update(['performance_review_id' => $performanceReviewId]);

            return $goal->fresh();
        });
    }

    /**
     * Get goal tree (hierarchical) for a tenant.
     */
    public function getGoalTree(?int $tenantId, ?int $userId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = HrGoal::forTenant($tenantId)
            ->whereNull('parent_goal_id')
            ->with(['childGoals.childGoals', 'user:id,name'])
            ->orderBy('priority', 'desc')
            ->orderBy('due_date');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }
}
