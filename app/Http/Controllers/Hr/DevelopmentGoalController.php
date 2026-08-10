<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Notifications\DevelopmentGoalAssignedNotification;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DevelopmentGoalController extends Controller
{
    public function __construct(private readonly HrPerformanceAccessService $access) {}

    /**
     * Development plans are now a tab inside the Goals & OKR hub. This legacy
     * index redirects there so old links and route() helpers still resolve.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        return redirect('/hr/goals?tab=development');
    }

    public function store(Request $request)
    {
        $user = $this->manager($request);

        $validated = $request->validate([
            'employee_user_id' => ['required', 'integer'],
            'manager_user_id' => ['nullable', 'integer'],
            'hr_goal_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'string', Rule::in(['growth', 'performance', 'leadership', 'compliance', 'capability'])],
            'competency_area' => ['nullable', 'string', 'max:255'],
            'competency_id' => ['nullable', 'integer'],
            'target_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'current_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', Rule::in(['not_started', 'in_progress', 'blocked', 'completed', 'cancelled'])],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'review_frequency' => ['nullable', 'string', Rule::in(['weekly', 'fortnightly', 'monthly', 'quarterly'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $employee = $this->access->currentStaff($user, (int) $validated['employee_user_id']);
        $manager = isset($validated['manager_user_id'])
            ? $this->access->currentStaff($user, (int) $validated['manager_user_id'])
            : null;
        $objective = isset($validated['hr_goal_id'])
            ? $this->objective($user, (int) $validated['hr_goal_id'])
            : null;
        $competency = isset($validated['competency_id'])
            ? HrCompetency::query()->active()->findOrFail((int) $validated['competency_id'])
            : null;

        // Seed the next review date so the reminder job has a target.
        $nextReview = $validated['due_date'] ?? null;
        if (! $nextReview && ! empty($validated['review_frequency'])) {
            $days = HrDevelopmentGoal::REVIEW_CADENCE_DAYS[$validated['review_frequency']] ?? null;
            $nextReview = $days ? now()->addDays($days)->toDateString() : null;
        }

        $goal = HrDevelopmentGoal::query()->create([
            ...$validated,
            'employee_user_id' => $employee->id,
            'manager_user_id' => $manager?->id,
            'hr_goal_id' => $objective?->id,
            'competency_id' => $competency?->id,
            'status' => $validated['status'] ?? 'not_started',
            'progress_percent' => (int) ($validated['progress_percent'] ?? 0),
            'next_review_at' => $nextReview,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $employee = User::find($goal->employee_user_id);
        if ($employee) {
            $employee->notify(new DevelopmentGoalAssignedNotification($goal));
        }

        return redirect()->back()->with('success', 'Development goal created.');
    }

    public function update(Request $request, HrDevelopmentGoal $goal)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $canManage = $user->canDo('hr.performance.manage');
        if ($canManage) {
            $this->access->currentStaff($user, $user);
            $goal = $this->developmentGoal($user, $goal);
        } else {
            $this->access->currentStaff($user, $user);
            $goal = HrDevelopmentGoal::query()
                ->where('employee_user_id', $user->id)
                ->findOrFail($goal->getKey());
        }

        $isGoalOwner = (int) $goal->employee_user_id === (int) $user->id;
        abort_unless($canManage || $isGoalOwner, 403);

        $validated = $request->validate([
            'title' => [$canManage ? 'sometimes' : 'prohibited', 'string', 'max:255'],
            'hr_goal_id' => [$canManage ? 'nullable' : 'prohibited', 'integer'],
            'description' => [$canManage ? 'nullable' : 'prohibited', 'string', 'max:5000'],
            'category' => [$canManage ? 'sometimes' : 'prohibited', 'string', Rule::in(['growth', 'performance', 'leadership', 'compliance', 'capability'])],
            'competency_area' => ['nullable', 'string', 'max:255'],
            'competency_id' => [$canManage ? 'nullable' : 'prohibited', 'integer'],
            'target_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'current_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['sometimes', 'string', Rule::in(['not_started', 'in_progress', 'blocked', 'completed', 'cancelled'])],
            'progress_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'start_date' => [$canManage ? 'nullable' : 'prohibited', 'date'],
            'due_date' => [$canManage ? 'nullable' : 'prohibited', 'date'],
            'review_frequency' => [$canManage ? 'nullable' : 'prohibited', 'string', Rule::in(['weekly', 'fortnightly', 'monthly', 'quarterly'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if ($canManage && isset($validated['hr_goal_id'])) {
            $validated['hr_goal_id'] = $this->objective($user, (int) $validated['hr_goal_id'])->id;
        }
        if ($canManage && isset($validated['competency_id'])) {
            $validated['competency_id'] = HrCompetency::query()
                ->active()
                ->findOrFail((int) $validated['competency_id'])
                ->id;
        }

        $payload = [
            ...$validated,
            'updated_by' => $user->id,
        ];

        // Re-seed the review target when cadence or the first-review date changes.
        if (array_key_exists('review_frequency', $validated) || array_key_exists('due_date', $validated)) {
            $freq = $validated['review_frequency'] ?? $goal->review_frequency;
            $due = $validated['due_date'] ?? optional($goal->due_date)->toDateString();
            $next = $due;
            if (! $next && $freq) {
                $days = HrDevelopmentGoal::REVIEW_CADENCE_DAYS[$freq] ?? null;
                $next = $days ? now()->addDays($days)->toDateString() : null;
            }
            $payload['next_review_at'] = $next;
        }

        [$goal, $wasCompleted] = DB::transaction(function () use ($goal, $payload, $user, $canManage): array {
            $locked = $canManage
                ? $this->access
                    ->applyHistoricalSubjectScope(HrDevelopmentGoal::query(), $user)
                    ->lockForUpdate()
                    ->findOrFail($goal->getKey())
                : HrDevelopmentGoal::query()
                    ->where('employee_user_id', $user->id)
                    ->lockForUpdate()
                    ->findOrFail($goal->getKey());
            $wasCompleted = $locked->status === 'completed';

            if (($payload['status'] ?? null) === 'completed') {
                $payload['completed_at'] = now()->toDateString();
                $payload['progress_percent'] = 100;
                $payload['next_review_at'] = null;
            }

            $locked->update($payload);

            return [$locked->fresh(), $wasCompleted];
        }, attempts: 1);

        // Completing the goal (a fresh transition) → tell the manager who set it.
        // The store path already notifies the employee on assignment; this is the
        // completion counterpart.
        if (($payload['status'] ?? null) === 'completed' && ! $wasCompleted) {
            app(HrNotificationService::class)->notifyDevelopmentGoalCompleted($goal->fresh(), $user->id);
        }

        return redirect()->back()->with('success', 'Development goal updated.');
    }

    public function destroy(Request $request, HrDevelopmentGoal $goal)
    {
        $user = $this->manager($request);
        $goal = $this->developmentGoal($user, $goal);

        DB::transaction(function () use ($goal, $user): void {
            $this->access
                ->applyHistoricalSubjectScope(HrDevelopmentGoal::query(), $user)
                ->lockForUpdate()
                ->findOrFail($goal->getKey())
                ->delete();
        }, attempts: 1);

        return redirect()->back()->with('success', 'Development goal deleted.');
    }

    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);
        $this->access->currentStaff($user, $user);

        return $user;
    }

    private function developmentGoal(User $viewer, HrDevelopmentGoal|int $goal): HrDevelopmentGoal
    {
        $goalId = $goal instanceof HrDevelopmentGoal ? $goal->getKey() : $goal;

        return $this->access
            ->applyHistoricalSubjectScope(HrDevelopmentGoal::query(), $viewer)
            ->findOrFail($goalId);
    }

    private function objective(User $viewer, int $goalId): HrGoal
    {
        return $this->access
            ->applyHistoricalSubjectScope(HrGoal::query(), $viewer, 'user_id')
            ->findOrFail($goalId);
    }
}
