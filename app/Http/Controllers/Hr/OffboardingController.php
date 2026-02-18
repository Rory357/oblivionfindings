<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Services\OnboardingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OffboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboardingService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);

        $tenantId = $user->tenant_id ?? null;
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $checklists = HrOffboardingChecklist::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->with([
                'employeeProfile.user:id,name,email',
                'creator:id,name',
            ])
            ->withCount([
                'tasks',
                'tasks as tasks_completed_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->whereHas('employeeProfile.user', fn ($users) =>
                $users->where('name', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $baseQuery = HrOffboardingChecklist::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId));

        $today = now()->toDateString();
        $nextWeek = now()->addDays(7)->toDateString();

        $summary = [
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'in_progress' => (clone $baseQuery)->where('status', 'in_progress')->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
            'overdue' => (clone $baseQuery)
                ->whereIn('status', ['pending', 'in_progress'])
                ->whereDate('due_date', '<', $today)
                ->count(),
            'due_next_7_days' => (clone $baseQuery)
                ->whereIn('status', ['pending', 'in_progress'])
                ->whereBetween('due_date', [$today, $nextWeek])
                ->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        return Inertia::render('hr/offboarding/index', [
            'checklists' => $checklists,
            'summary' => $summary,
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    public function show(Request $request, HrOffboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);
        if (($user->tenant_id ?? null) !== null && $checklist->tenant_id !== $user->tenant_id) {
            abort(404);
        }

        $checklist->load([
            'employeeProfile.user:id,name,email',
            'employeeProfile.primarySite:id,name',
            'tasks' => fn ($query) => $query->orderBy('sort_order'),
            'tasks.assignedTo:id,name',
            'tasks.completedBy:id,name',
            'creator:id,name',
        ]);

        return Inertia::render('hr/offboarding/show', [
            'checklist' => $checklist,
            'progress' => $this->onboardingService->getProgress($checklist),
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $tenantId = $user->tenant_id ?? null;

        $existingProfileIds = HrOffboardingChecklist::query()
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereIn('status', ['pending', 'in_progress'])
            ->pluck('employee_profile_id');

        $employees = HrEmployeeProfile::query()
            ->with('user:id,name,email')
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('is_active', true)
            ->whereNotIn('id', $existingProfileIds)
            ->get()
            ->map(fn ($profile) => [
                'id' => $profile->id,
                'name' => $profile->user?->name ?? 'Unknown',
                'email' => $profile->user?->email,
                'position_title' => $profile->position_title,
                'end_date' => optional($profile->end_date)->toDateString(),
            ]);

        return Inertia::render('hr/offboarding/create', [
            'employees' => $employees,
            'defaultEndDate' => now()->addWeeks(2)->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $validated = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'end_date' => ['nullable', 'date'],
        ]);

        $profile = HrEmployeeProfile::query()->findOrFail((int) $validated['employee_profile_id']);
        if (($user->tenant_id ?? null) !== null && $profile->tenant_id !== $user->tenant_id) {
            abort(404);
        }

        $existing = HrOffboardingChecklist::query()
            ->where('employee_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($existing) {
            return redirect()->back()->with('error', 'An active offboarding checklist already exists for this employee.');
        }

        $checklist = $this->onboardingService->generateOffboardingChecklist(
            $profile,
            $user->id,
            ['end_date' => $validated['end_date'] ?? null]
        );

        return redirect()->route('hr.offboarding.show', $checklist)
            ->with('success', "Offboarding checklist created with {$checklist->tasks->count()} tasks.");
    }

    public function completeTask(Request $request, HrOffboardingTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $checklist = $task->checklist;
        abort_unless($checklist, 404);
        if (($user->tenant_id ?? null) !== null && $checklist->tenant_id !== $user->tenant_id) {
            abort(404);
        }

        $validated = $request->validate([
            'evidence_path' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'signed_off_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if ($task->sign_off_required && empty($validated['signed_off_by'])) {
            return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');
        }

        try {
            $this->onboardingService->completeOffboardingTask($task, $user->id, $validated);
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Task '{$task->title}' completed.");
    }
}
