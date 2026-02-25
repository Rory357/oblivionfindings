<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Notifications\DevelopmentGoalAssignedNotification;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DevelopmentGoalController extends Controller
{
    use ResolvesHrTenant;

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $canManage = $user->canDo('hr.performance.manage');
        $status = $request->query('status');
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);

        $goals = HrDevelopmentGoal::query()
            ->with(['employee:id,name,email', 'manager:id,name'])
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->when(! $canManage, fn ($query) => $query->where('employee_user_id', $user->id))
            ->orderByRaw("CASE WHEN status = 'in_progress' THEN 0 WHEN status = 'not_started' THEN 1 WHEN status = 'blocked' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (HrDevelopmentGoal $goal) => [
                'id' => $goal->id,
                'title' => $goal->title,
                'description' => $goal->description,
                'category' => $goal->category,
                'competency_area' => $goal->competency_area,
                'target_level' => $goal->target_level,
                'current_level' => $goal->current_level,
                'status' => $goal->status,
                'progress_percent' => (int) $goal->progress_percent,
                'start_date' => optional($goal->start_date)->toDateString(),
                'due_date' => optional($goal->due_date)->toDateString(),
                'completed_at' => optional($goal->completed_at)->toDateString(),
                'review_frequency' => $goal->review_frequency,
                'review_notes' => $goal->review_notes,
                'employee' => $goal->employee ? [
                    'id' => $goal->employee->id,
                    'name' => $goal->employee->name,
                    'email' => $goal->employee->email,
                ] : null,
                'manager' => $goal->manager ? [
                    'id' => $goal->manager->id,
                    'name' => $goal->manager->name,
                ] : null,
            ]);

        $staff = User::query()
            ->staff()
            ->when($tenantStaffIds !== [], fn ($query) => $query->whereIn('id', $tenantStaffIds))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/development/goals', [
            'goals' => $goals,
            'staff' => $staff,
            'filters' => [
                'status' => $status,
            ],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.performance.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $tenantStaffIds = $this->hrStaffUserIdsForTenant($tenantId);
        $employeeRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');
        $managerRule = $tenantStaffIds !== [] ? Rule::in($tenantStaffIds) : Rule::exists('users', 'id');

        $validated = $request->validate([
            'employee_user_id' => ['required', 'integer', $employeeRule],
            'manager_user_id' => ['nullable', 'integer', $managerRule],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', 'string', Rule::in(['growth', 'performance', 'leadership', 'compliance', 'capability'])],
            'competency_area' => ['nullable', 'string', 'max:255'],
            'target_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'current_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'status' => ['nullable', 'string', Rule::in(['not_started', 'in_progress', 'blocked', 'completed', 'cancelled'])],
            'progress_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'review_frequency' => ['nullable', 'string', Rule::in(['weekly', 'fortnightly', 'monthly', 'quarterly'])],
            'review_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $goal = HrDevelopmentGoal::create([
            'tenant_id' => $tenantId,
            ...$validated,
            'status' => $validated['status'] ?? 'not_started',
            'progress_percent' => (int) ($validated['progress_percent'] ?? 0),
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

        $validated = $request->validate([
            'title' => [$canManage ? 'sometimes' : 'prohibited', 'string', 'max:255'],
            'description' => [$canManage ? 'nullable' : 'prohibited', 'string', 'max:5000'],
            'category' => [$canManage ? 'sometimes' : 'prohibited', 'string', Rule::in(['growth', 'performance', 'leadership', 'compliance', 'capability'])],
            'competency_area' => ['nullable', 'string', 'max:255'],
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

        if (($payload['status'] ?? null) === 'completed') {
            $payload['completed_at'] = now()->toDateString();
            $payload['progress_percent'] = 100;
        }

        $goal->update($payload);

        return redirect()->back()->with('success', 'Development goal updated.');
    }
}
