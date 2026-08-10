<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Services\ExitInterviewService;
use App\Domain\Hr\Services\HrLifecycleAccessService;
use App\Domain\Hr\Services\OnboardingService;
use App\Http\Controllers\Controller;
use App\Models\AssetAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OffboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboardingService,
        private readonly ExitInterviewService $exitInterviewService,
        private readonly HrLifecycleAccessService $lifecycleAccess,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);

        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $checklists = $this->lifecycleAccess->visibleOffboardingChecklists($user)
            ->select([
                'id',
                'employee_profile_id',
                'template_key',
                'status',
                'started_at',
                'completed_at',
                'due_date',
                'created_at',
            ])
            ->with([
                'employeeProfile:id,user_id',
                'employeeProfile.user:id,name',
            ])
            ->withCount([
                'tasks',
                'tasks as tasks_completed_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->whereHas('employeeProfile.user', fn ($users) => $users->where('name', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $baseQuery = $this->lifecycleAccess->visibleOffboardingChecklists($user);

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
            'employees' => $this->eligibleEmployees($user),
            'interviewers' => $this->interviewerOptions($user),
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
    private function eligibleEmployees(User $viewer): Collection
    {
        $existingProfileIds = $this->lifecycleAccess->visibleOffboardingChecklists($viewer)
            ->whereIn('status', ['pending', 'in_progress'])
            ->pluck('employee_profile_id');

        $profiles = $this->lifecycleAccess->currentProfiles($viewer)
            ->with('user:id,name,email')
            ->whereNotIn('id', $existingProfileIds)
            ->get(['id', 'user_id', 'position_title', 'end_date']);

        $userIds = $profiles->pluck('user_id')->filter()->values();
        $assetIds = $this->lifecycleAccess->authorizedAssetIds($viewer);

        $assetsByUser = AssetAssignment::query()
            ->with('asset:id,name,asset_tag')
            ->whereIn('asset_id', $assetIds)
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
    private function interviewerOptions(User $viewer): Collection
    {
        return $this->lifecycleAccess->currentUsers($viewer)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }

    public function show(Request $request, HrOffboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);
        $checklist = $this->lifecycleAccess->visibleOffboardingChecklist($user, $checklist);

        $checklist->load([
            'employeeProfile:id,user_id',
            'employeeProfile.user:id,name',
            'tasks' => fn ($query) => $query
                ->select([
                    'id',
                    'offboarding_checklist_id',
                    'exit_interview_id',
                    'category',
                    'title',
                    'description',
                    'is_required',
                    'sort_order',
                    'assigned_to_user_id',
                    'status',
                    'due_date',
                    'completed_at',
                    'sign_off_required',
                    'notes',
                ])
                ->orderBy('sort_order'),
            'tasks.assignedTo:id,name',
        ]);

        return Inertia::render('hr/offboarding/show', [
            'checklist' => $checklist,
            'progress' => $this->onboardingService->getProgress($checklist),
            'interviewers' => $this->interviewerOptions($user),
            'departureReasons' => self::DEPARTURE_REASONS,
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    /**
     * The start-offboarding form is a wizard modal on the index page — keep
     * the old GET route working by bouncing to the index with the wizard open.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        return redirect()->route('hr.offboarding.index', ['new' => 1]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $validated = $request->validate([
            'employee_profile_id' => ['required', 'integer'],
            'end_date' => ['nullable', 'date'],
            'schedule_exit_interview' => ['sometimes', 'boolean'],
            'departure_reason' => ['nullable', 'string', 'max:255', 'required_if:schedule_exit_interview,true'],
            'interviewer_user_id' => ['nullable', 'integer', 'required_if:schedule_exit_interview,true'],
            'interview_date' => ['nullable', 'date', 'required_if:schedule_exit_interview,true'],
        ]);

        try {
            $checklist = DB::transaction(function () use ($user, $validated): HrOffboardingChecklist {
                $profile = $this->lifecycleAccess->currentProfile(
                    $user,
                    (int) $validated['employee_profile_id'],
                    true,
                );

                if ($validated['schedule_exit_interview'] ?? false) {
                    $this->lifecycleAccess->currentUser(
                        $user,
                        (int) $validated['interviewer_user_id'],
                        true,
                    );
                }

                $existing = HrOffboardingChecklist::query()
                    ->where('employee_profile_id', $profile->id)
                    ->whereIn('status', ['pending', 'in_progress'])
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    throw new \LogicException('An active offboarding checklist already exists for this employee.');
                }

                $checklist = $this->onboardingService->generateOffboardingChecklist(
                    $profile,
                    $user->id,
                    ['end_date' => $validated['end_date'] ?? null],
                );

                if ($validated['schedule_exit_interview'] ?? false) {
                    $exitInterviewTaskId = $checklist->tasks()
                        ->where('notes', 'like', '%workflow_key=exit_interview%')
                        ->value('id');

                    $this->exitInterviewService->createExitInterview([
                        'created_by' => $user->id,
                        'employee_profile_id' => $profile->id,
                        'interviewer_user_id' => (int) $validated['interviewer_user_id'],
                        'interview_date' => $validated['interview_date'],
                        'departure_reason' => $validated['departure_reason'],
                        'offboarding_task_id' => $exitInterviewTaskId,
                    ]);
                }

                return $checklist;
            });
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->route('hr.offboarding.show', $checklist)
            ->with('success', "Offboarding checklist created with {$checklist->tasks->count()} tasks.");
    }

    public function completeTask(Request $request, HrOffboardingTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $validated = $request->validate([
            'evidence_path' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'signed_off_by' => ['nullable', 'integer'],
        ]);

        try {
            DB::transaction(function () use ($user, $task, $validated): void {
                $lockedTask = $this->lifecycleAccess->visibleOffboardingTask($user, $task, true);
                if ($lockedTask->sign_off_required && empty($validated['signed_off_by'])) {
                    throw new \LogicException('This task requires sign-off. Please specify the sign-off user.');
                }
                if (! empty($validated['signed_off_by'])) {
                    $this->lifecycleAccess->currentUser(
                        $user,
                        (int) $validated['signed_off_by'],
                        true,
                    );
                }
                $this->onboardingService->completeOffboardingTask($lockedTask, $user->id, $validated);
            });
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Task '{$task->title}' completed.");
    }

    /**
     * Reopen a mistakenly-completed task. Never restores revoked system
     * access — rehire owns restoration.
     */
    public function uncompleteTask(Request $request, HrOffboardingTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        DB::transaction(function () use ($user, $task): void {
            $lockedTask = $this->lifecycleAccess->visibleOffboardingTask($user, $task, true);
            $this->onboardingService->uncompleteOffboardingTask($lockedTask);
        });

        return redirect()->back()->with('success', 'Task reopened.');
    }

    /**
     * Cancel or archive an offboarding without deleting it — e.g. a retracted
     * resignation. Append-only history.
     */
    public function setStatus(Request $request, HrOffboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $checklist = $this->lifecycleAccess->visibleOffboardingChecklist($user, $checklist);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:in_progress,cancelled,archived'],
        ]);

        DB::transaction(function () use ($user, $checklist, $validated): void {
            $lockedChecklist = $this->lifecycleAccess->visibleOffboardingChecklist($user, $checklist, true);
            $this->onboardingService->setOffboardingChecklistStatus($lockedChecklist, $validated['status']);
        });

        return redirect()->back()->with('success', 'Offboarding updated.');
    }
}
