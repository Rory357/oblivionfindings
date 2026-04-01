<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalUpdate;
use App\Domain\Hr\Models\HrKeyResult;
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

            // Cascade: recalculate parent goal progress
            if ($goal->parent_goal_id) {
                $this->recalculateGoalProgress($goal->parentGoal);
            }

            return $update;
        });
    }

    /**
     * Update a key result's progress and cascade to parent goal.
     */
    public function updateKeyResultProgress(HrKeyResult $keyResult, array $data): HrKeyResult
    {
        return DB::transaction(function () use ($keyResult, $data) {
            $keyResult->current_value = $data['current_value'] ?? $keyResult->current_value;
            $keyResult->recalculateProgress();

            if (isset($data['status'])) {
                $keyResult->status = $data['status'];
            }

            $keyResult->save();

            // Recalculate parent goal from all its KRs
            $this->recalculateGoalProgress($keyResult->goal);

            return $keyResult->fresh();
        });
    }

    /**
     * Recalculate a goal's progress from its key results and/or child goals.
     */
    public function recalculateGoalProgress(HrGoal $goal): void
    {
        $goal->loadMissing(['keyResults', 'childGoals']);

        $sources = collect();

        // Key results contribute to progress
        if ($goal->keyResults->isNotEmpty()) {
            $sources = $goal->keyResults->pluck('progress_percentage');
        }

        // Child goals also contribute
        if ($goal->childGoals->isNotEmpty()) {
            $childProgress = $goal->childGoals->pluck('progress_percentage');
            $sources = $sources->merge($childProgress);
        }

        if ($sources->isNotEmpty()) {
            $avgProgress = (int) round($sources->avg());

            $goalUpdate = ['progress_percentage' => $avgProgress];

            if ($avgProgress >= 100 && $goal->status === 'active') {
                $goalUpdate['status'] = 'completed';
                $goalUpdate['completed_at'] = now();
            }

            $goal->update($goalUpdate);

            // Continue cascading up
            if ($goal->parent_goal_id) {
                $this->recalculateGoalProgress($goal->parentGoal);
            }
        }
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
            ->with(['childGoals.childGoals.user:id,name', 'childGoals.user:id,name', 'user:id,name', 'keyResults'])
            ->orderBy('priority', 'desc')
            ->orderBy('due_date');

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->get();
    }

    /**
     * Get cascading company → team → individual tree.
     */
    public function getCompanyGoalTree(?int $tenantId): array
    {
        $companyGoals = HrGoal::forTenant($tenantId)
            ->where('goal_type', 'company')
            ->whereNull('deleted_at')
            ->with([
                'user:id,name',
                'keyResults',
                'childGoals' => fn ($q) => $q->where('goal_type', 'team')->with([
                    'user:id,name',
                    'keyResults',
                    'childGoals' => fn ($q2) => $q2->where('goal_type', 'individual')->with(['user:id,name', 'keyResults']),
                ]),
            ])
            ->orderBy('priority', 'desc')
            ->get();

        return $companyGoals->map(fn (HrGoal $g) => $this->mapGoalForTree($g))->toArray();
    }

    /**
     * Get analytics/dashboard data.
     */
    public function getGoalAnalytics(?int $tenantId): array
    {
        $baseQuery = HrGoal::forTenant($tenantId)->whereNull('deleted_at');

        $total = (clone $baseQuery)->count();
        $active = (clone $baseQuery)->where('status', 'active')->count();
        $completed = (clone $baseQuery)->where('status', 'completed')->count();
        $draft = (clone $baseQuery)->where('status', 'draft')->count();

        $overdue = (clone $baseQuery)
            ->where('status', 'active')
            ->where('due_date', '<', now()->toDateString())
            ->count();

        $onTrack = (clone $baseQuery)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('due_date')->orWhere('due_date', '>=', now()->toDateString());
            })
            ->count();

        $completionRate = $total > 0 ? round(($completed / $total) * 100) : 0;

        // Average progress by type
        $progressByType = (clone $baseQuery)
            ->where('status', 'active')
            ->selectRaw('goal_type, AVG(progress_percentage) as avg_progress, COUNT(*) as count')
            ->groupBy('goal_type')
            ->get()
            ->map(fn ($row) => [
                'type' => $row->goal_type,
                'avg_progress' => round((float) $row->avg_progress),
                'count' => $row->count,
            ])
            ->values()
            ->toArray();

        // Monthly completions (last 6 months)
        $monthlyCompletions = (clone $baseQuery)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subMonths(6))
            ->selectRaw("DATE_FORMAT(completed_at, '%Y-%m') as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        return [
            'total' => $total,
            'active' => $active,
            'completed' => $completed,
            'draft' => $draft,
            'overdue' => $overdue,
            'on_track' => $onTrack,
            'completion_rate' => $completionRate,
            'progress_by_type' => $progressByType,
            'monthly_completions' => $monthlyCompletions,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    private function mapGoalForTree(HrGoal $goal): array
    {
        return [
            'id' => $goal->id,
            'title' => $goal->title,
            'goal_type' => $goal->goal_type,
            'status' => $goal->status,
            'priority' => $goal->priority,
            'progress_percentage' => $goal->progress_percentage,
            'due_date' => $goal->due_date?->toDateString(),
            'user' => $goal->user ? ['id' => $goal->user->id, 'name' => $goal->user->name] : null,
            'key_results_count' => $goal->keyResults->count(),
            'children' => $goal->childGoals->map(fn (HrGoal $child) => $this->mapGoalForTree($child))->toArray(),
        ];
    }
}
