<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Services\ExitInterviewService;
use App\Domain\Hr\Services\OnboardingService;
use App\Http\Controllers\Controller;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OffboardingController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly OnboardingService $onboardingService,
        private readonly ExitInterviewService $exitInterviewService,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $checklists = HrOffboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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
            ->where('tenant_id', $tenantId);

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
            'employees' => $this->eligibleEmployees($tenantId),
            'interviewers' => $this->interviewerOptions($tenantId, $user),
            'departureReasons' => self::DEPARTURE_REASONS,
            'defaultTasks' => $this->onboardingService->defaultOffboardingTasks(),
            'defaultEndDate' => now()->addWeeks(2)->toDateString(),
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    /**
     * Departure-reason taxonomy, shared with the exit-interview form.
     */
    private const DEPARTURE_REASONS = [
        ['value' => 'career_growth', 'label' => 'Career Growth'],
        ['value' => 'compensation', 'label' => 'Compensation'],
        ['value' => 'work_life_balance', 'label' => 'Work-Life Balance'],
        ['value' => 'management', 'label' => 'Management Issues'],
        ['value' => 'culture', 'label' => 'Company Culture'],
        ['value' => 'relocation', 'label' => 'Relocation'],
        ['value' => 'retirement', 'label' => 'Retirement'],
        ['value' => 'personal', 'label' => 'Personal Reasons'],
        ['value' => 'redundancy', 'label' => 'Redundancy'],
        ['value' => 'contract_end', 'label' => 'Contract End'],
        ['value' => 'other', 'label' => 'Other'],
    ];

    /**
     * Active employees without an in-flight offboarding checklist, each with an
     * active-asset-return preview for the wizard. Assets are batch-loaded to
     * avoid an N+1 across the candidate list.
     */
    private function eligibleEmployees(int $tenantId): \Illuminate\Support\Collection
    {
        $existingProfileIds = HrOffboardingChecklist::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->pluck('employee_profile_id');

        $profiles = HrEmployeeProfile::query()
            ->with('user:id,name,email')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotIn('id', $existingProfileIds)
            ->get();

        $userIds = $profiles->pluck('user_id')->filter()->values();

        $assetsByUser = AssetAssignment::query()
            ->with('asset:id,name,asset_tag')
            ->whereIn('assignee_type', ['staff', 'user', User::class])
            ->whereIn('assignee_id', $userIds)
            ->whereNull('released_at')
            ->get()
            ->groupBy('assignee_id');

        return $profiles->map(fn (HrEmployeeProfile $profile) => [
            'id' => $profile->id,
            'name' => $profile->user?->name ?? 'Unknown',
            'email' => $profile->user?->email,
            'position_title' => $profile->position_title,
            'end_date' => optional($profile->end_date)->toDateString(),
            'active_assets' => ($assetsByUser[$profile->user_id] ?? collect())
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->asset?->name ?? 'Asset',
                    'asset_tag' => $a->asset?->asset_tag,
                ])
                ->values(),
        ])->values();
    }

    /**
     * Users who can be recorded as exit-interview interviewers.
     */
    private function interviewerOptions(int $tenantId, User $user): \Illuminate\Support\Collection
    {
        $ids = HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->pluck('user_id')
            ->push($user->id)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }

    public function show(Request $request, HrOffboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

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
            'interviewers' => $this->interviewerOptions($tenantId, $user),
            'departureReasons' => self::DEPARTURE_REASONS,
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $existingProfileIds = HrOffboardingChecklist::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->pluck('employee_profile_id');

        $employees = HrEmployeeProfile::query()
            ->with('user:id,name,email')
            ->where('tenant_id', $tenantId)
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
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'end_date' => ['nullable', 'date'],
            'schedule_exit_interview' => ['sometimes', 'boolean'],
            'departure_reason' => ['nullable', 'string', 'max:255', 'required_if:schedule_exit_interview,true'],
            'interviewer_user_id' => ['nullable', 'integer', 'exists:users,id', 'required_if:schedule_exit_interview,true'],
            'interview_date' => ['nullable', 'date', 'required_if:schedule_exit_interview,true'],
        ]);

        $profile = HrEmployeeProfile::query()->findOrFail((int) $validated['employee_profile_id']);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

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

        // Optionally schedule the exit interview as part of the same flow, so the
        // checklist's "Exit interview" task is backed by a real HrExitInterview.
        if (($validated['schedule_exit_interview'] ?? false)) {
            $this->exitInterviewService->createExitInterview([
                'tenant_id' => $tenantId,
                'created_by' => $user->id,
                'employee_profile_id' => $profile->id,
                'interviewer_user_id' => (int) $validated['interviewer_user_id'],
                'interview_date' => $validated['interview_date'],
                'departure_reason' => $validated['departure_reason'],
            ]);
        }

        return redirect()->route('hr.offboarding.show', $checklist)
            ->with('success', "Offboarding checklist created with {$checklist->tasks->count()} tasks.");
    }

    public function completeTask(Request $request, HrOffboardingTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $checklist = $task->checklist;
        abort_unless($checklist, 404);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

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
