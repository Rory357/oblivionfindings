<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Notifications\DevelopmentGoalAssignedNotification;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DevelopmentGoalController extends Controller
{
    use ResolvesHrTenant;

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
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $employeeRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');
        $managerRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');
        $goalRule = Rule::exists('hr_goals', 'id');
        if ($tenantId !== null) {
            $goalRule->where('tenant_id', $tenantId);
        }

        $validated = $request->validate([
            'employee_user_id' => ['required', 'integer', $employeeRule],
            'manager_user_id' => ['nullable', 'integer', $managerRule],
            'hr_goal_id' => ['nullable', 'integer', $goalRule],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'string', Rule::in(['growth', 'performance', 'leadership', 'compliance', 'capability'])],
            'competency_area' => ['nullable', 'string', 'max:255'],
            'competency_id' => ['nullable', 'integer', Rule::exists('hr_competencies', 'id')],
            'target_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'current_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', Rule::in(['not_started', 'in_progress', 'blocked', 'completed', 'cancelled'])],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'review_frequency' => ['nullable', 'string', Rule::in(['weekly', 'fortnightly', 'monthly', 'quarterly'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Seed the next review date so the reminder job has a target.
        $nextReview = $validated['due_date'] ?? null;
        if (! $nextReview && ! empty($validated['review_frequency'])) {
            $days = HrDevelopmentGoal::REVIEW_CADENCE_DAYS[$validated['review_frequency']] ?? null;
            $nextReview = $days ? now()->addDays($days)->toDateString() : null;
        }

        $goal = HrDevelopmentGoal::create([
            'tenant_id' => $tenantId,
            ...$validated,
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $goal->tenant_id);

        $canManage = $user->canDo('hr.performance.manage');
        $isGoalOwner = $goal->employee_user_id === $user->id;
        abort_unless($canManage || $isGoalOwner, 403);

        $goalRule = Rule::exists('hr_goals', 'id');
        if ($tenantId !== null) {
            $goalRule->where('tenant_id', $tenantId);
        }

        $validated = $request->validate([
            'title' => [$canManage ? 'sometimes' : 'prohibited', 'string', 'max:255'],
            'hr_goal_id' => [$canManage ? 'nullable' : 'prohibited', 'integer', $goalRule],
            'description' => [$canManage ? 'nullable' : 'prohibited', 'string', 'max:5000'],
            'category' => [$canManage ? 'sometimes' : 'prohibited', 'string', Rule::in(['growth', 'performance', 'leadership', 'compliance', 'capability'])],
            'competency_area' => ['nullable', 'string', 'max:255'],
            'competency_id' => [$canManage ? 'nullable' : 'prohibited', 'integer', Rule::exists('hr_competencies', 'id')],
            'target_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'current_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['sometimes', 'string', Rule::in(['not_started', 'in_progress', 'blocked', 'completed', 'cancelled'])],
            'progress_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'start_date' => [$canManage ? 'nullable' : 'prohibited', 'date'],
            'due_date' => [$canManage ? 'nullable' : 'prohibited', 'date'],
            'review_frequency' => [$canManage ? 'nullable' : 'prohibited', 'string', Rule::in(['weekly', 'fortnightly', 'monthly', 'quarterly'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

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

        if (($payload['status'] ?? null) === 'completed') {
            $payload['completed_at'] = now()->toDateString();
            $payload['progress_percent'] = 100;
            $payload['next_review_at'] = null;
        }

        $goal->update($payload);

        return redirect()->back()->with('success', 'Development goal updated.');
    }

    public function destroy(Request $request, HrDevelopmentGoal $goal)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $goal->tenant_id);

        abort_unless($user->canDo('hr.performance.manage'), 403);

        $goal->delete();

        return redirect()->back()->with('success', 'Development goal deleted.');
    }
}
