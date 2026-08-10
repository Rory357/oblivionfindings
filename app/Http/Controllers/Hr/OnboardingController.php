<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Jobs\SendOnboardingEmailJob;
use App\Domain\Hr\Models\HrCourse;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Models\HrOnboardingEmailLog;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Notifications\OnboardingOwnerReassignedNotification;
use App\Domain\Hr\Notifications\OnboardingTaskDueNotification;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Domain\Hr\Services\HrLifecycleAccessService;
use App\Domain\Hr\Services\OnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\CompleteOnboardingTaskRequest;
use App\Http\Requests\Hr\ProvisionOnboardingAssetRequest;
use App\Http\Requests\Hr\StoreOnboardingChecklistRequest;
use App\Http\Requests\Hr\StoreOnboardingTaskRequest;
use App\Http\Requests\Hr\StoreOnboardingTemplateRequest;
use App\Http\Requests\Hr\UpdateOnboardingTaskRequest;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OnboardingController extends Controller
{
    /** Terminal statuses that should not appear as "active" work. */
    private const TERMINAL = ['completed', 'cancelled', 'archived'];

    public function __construct(
        private readonly OnboardingService $onboardingService,
        private readonly HrLifecycleAccessService $lifecycleAccess,
    ) {}

    /* ================================================================== */
    /*  Hub */
    /* ================================================================== */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);

        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $checklists = $this->lifecycleAccess->visibleOnboardingChecklists($user)
            ->with([
                'employeeProfile.user:id,name,email',
                'employeeProfile.primarySite:id,name,type',
                'creator:id,name',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->when($status === 'overdue', fn ($query) => $query
                ->whereNotIn('status', self::TERMINAL)
                ->whereDate('due_date', '<', now()->toDateString()))
            ->when($status && $status !== 'overdue', fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->whereHas('employeeProfile.user', fn ($users) => $users->where('name', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $checklists->through(fn (HrOnboardingChecklist $checklist) => $this->checklistResource($checklist));

        // Quick-peek drawer: only hydrated when the row drawer is open (partial
        // reload with ?drawer={id}), so the hub stays light on first paint.
        $drawerId = $request->query('drawer');
        $drawerChecklist = $drawerId
            ? $this->drawerPayload($user, (int) $drawerId)
            : null;

        return Inertia::render('hr/onboarding/index', [
            'checklists' => $checklists,
            'drawerChecklist' => $drawerChecklist,
            'summary' => $this->buildSummary($user),
            'overview' => $this->overviewData($user),
            'templates' => $this->templatePayload(),
            'emails' => $this->emailPayload($user),
            'employees' => $this->eligibleEmployees($user),
            'emailTemplates' => $this->emailTemplateOptions(),
            'owners' => $this->currentUserOptions($user),
            'newHireOptions' => $this->newHireOptions($user),
            'templateRoleOptions' => $this->roleOptions(),
            'courseOptions' => $this->courseOptions(),
            'siteTypeOptions' => ['all', 'head_office', 'house', 'facility', 'residential'],
            'filters' => [
                'status' => $status,
                'q' => $search,
            ],
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);
        $rows = $this->lifecycleAccess->visibleOnboardingChecklists($user)
            ->with(['employeeProfile.user:id,name', 'employeeProfile.primarySite:id,name', 'creator:id,name'])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->orderByDesc('created_at')
            ->get();

        $filename = 'onboarding-checklists-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Employee', 'Role', 'Site', 'Template', 'Status', 'Tasks done', 'Tasks total', 'Owner', 'Due date']);
            foreach ($rows as $c) {
                $this->putCsv($out, [
                    $c->employeeProfile?->user?->name ?? 'Unknown',
                    $c->employeeProfile?->position_title ?? $c->employeeProfile?->position_role,
                    $c->employeeProfile?->primarySite?->name,
                    $c->template_key,
                    $c->status,
                    $c->completed_tasks_count,
                    $c->tasks_count,
                    $c->creator?->name,
                    optional($c->due_date)->toDateString(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function show(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $checklist->load([
            'employeeProfile.user:id,name,email',
            'employeeProfile.primarySite:id,name,type',
            'tasks' => fn ($query) => $query->orderBy('sort_order'),
            'tasks.assignedTo:id,name',
            'tasks.completedBy:id,name',
            'tasks.signedOffBy:id,name',
            'creator:id,name',
        ]);

        $profile = $checklist->employeeProfile;
        $today = now()->startOfDay();

        $tasks = $checklist->tasks
            ->sortBy('sort_order')
            ->values()
            ->map(function (HrOnboardingTask $task) use ($today) {
                $isOverdue = $task->status !== 'completed'
                    && $task->due_date
                    && Carbon::parse($task->due_date)->startOfDay()->lt($today);

                return [
                    'id' => $task->id,
                    'category' => $task->category ?: 'general',
                    'title' => $task->title,
                    'description' => $task->description,
                    'is_required' => (bool) $task->is_required,
                    'sign_off_required' => (bool) $task->sign_off_required,
                    'status' => $task->status,
                    'is_completed' => $task->status === 'completed',
                    'sort_order' => (int) $task->sort_order,
                    'due_date' => optional($task->due_date)->toDateString(),
                    'is_overdue' => $isOverdue,
                    'assigned_to_user_id' => $task->assigned_to_user_id,
                    'assignee' => $task->assignedTo?->name,
                    'assigned_to_role' => $task->assigned_to_role,
                    'completed_at' => optional($task->completed_at)->toDateTimeString(),
                    'completed_by' => $task->completedBy?->name,
                    'signed_off_by' => $task->signedOffBy?->name,
                    'evidence_path' => $task->evidence_path,
                    'notes' => $task->notes,
                ];
            });

        return Inertia::render('hr/onboarding/show', [
            'checklist' => [
                'id' => $checklist->id,
                'status' => $checklist->status,
                'template_key' => $checklist->template_key,
                'started_at' => optional($checklist->started_at)->toDateTimeString(),
                'completed_at' => optional($checklist->completed_at)->toDateTimeString(),
                'due_date' => optional($checklist->due_date)->toDateString(),
                'owner' => $checklist->creator?->name,
                'employee' => [
                    'id' => $profile?->id,
                    'name' => $profile?->user?->name ?? 'Unknown',
                    'email' => $profile?->user?->email,
                    'position_title' => $profile?->position_title,
                    'position_role' => $profile?->position_role,
                    'site_name' => $profile?->primarySite?->name,
                    'start_date' => optional($profile?->start_date)->toDateString(),
                ],
                'tasks' => $tasks,
            ],
            'progress' => $this->onboardingService->getProgress($checklist),
            'owners' => $this->currentUserOptions($user),
            'provisionableAssets' => $this->provisionableAssets($user),
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    /** Active company assets not currently issued — the IT-provisioning picks. */
    private function provisionableAssets(User $viewer): Collection
    {
        $assignedIds = AssetAssignment::query()->whereNull('released_at')->pluck('asset_id');
        $assetIds = $this->lifecycleAccess->authorizedAssetIds($viewer);

        return Asset::query()
            ->whereIn('id', $assetIds)
            ->where('status', 'active')
            ->whereNotIn('id', $assignedIds)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'asset_tag'])
            ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'asset_tag' => $a->asset_tag])
            ->values();
    }

    public function create(Request $request)
    {
        // Legacy single-field create page retired in favour of the hub wizard.
        return redirect()->route('hr.onboarding.index');
    }

    /* ================================================================== */
    /*  Start onboarding (existing employee + new hire — one path) */
    /* ================================================================== */

    public function store(StoreOnboardingChecklistRequest $request, EmployeeIntakeService $intake)
    {
        $user = $request->user();
        $validated = $request->validated();

        if (($validated['hire_mode'] ?? 'existing') === 'new') {
            $siteId = (int) $validated['primary_site_id'];
            abort_unless(in_array($siteId, $this->lifecycleAccess->accessibleSiteIds($user), true), 404);
            if (! empty($validated['manager_user_id'])) {
                $manager = $this->lifecycleAccess->currentUser($user, (int) $validated['manager_user_id']);
                abort_unless($this->lifecycleAccess->canAccessEverySite($manager, [$siteId]), 404);
            }

            // Converged new-hire path: create the person via the single intake
            // door (no onboarding yet — we generate it explicitly below so the
            // chosen template / compliance / welcome-email options are honoured).
            $roleName = $validated['role'] ?? 'support_worker';
            try {
                $profile = $intake->intake(
                    name: $validated['name'],
                    email: $validated['email'],
                    roleName: $roleName,
                    profileAttributes: [
                        'position_title' => $validated['position_title'] ?? 'New starter',
                        'position_role' => $roleName,
                        'employment_type' => $validated['employment_type'] ?? 'full_time',
                        'primary_site_id' => $validated['primary_site_id'] ?? null,
                        'manager_user_id' => $validated['manager_user_id'] ?? null,
                        'start_date' => $validated['start_date'] ?? now()->toDateString(),
                    ],
                    actorId: $user->id,
                    startOnboarding: false,
                    sendInvite: false,
                    source: 'onboarding_wizard',
                );
            } catch (\InvalidArgumentException $e) {
                // D-2 role-assignment guard (admin-grade / external personas).
                return redirect()->back()->with('error', $e->getMessage());
            }
        } else {
            $profile = $this->lifecycleAccess->onboardingProfile(
                $user,
                (int) $validated['employee_profile_id'],
            );
        }

        if (($validated['send_welcome_email'] ?? false) && ! empty($validated['welcome_email_id'])) {
            HrOnboardingEmail::query()
                ->where('is_active', true)
                ->findOrFail((int) $validated['welcome_email_id']);
        }

        $existing = HrOnboardingChecklist::query()
            ->where('employee_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($existing) {
            return redirect()->back()->with('error', 'An active onboarding checklist already exists for this employee.');
        }

        try {
            $checklist = $this->onboardingService->generateChecklist(
                $profile,
                $user->id,
                ! empty($validated['template_id']) ? (int) $validated['template_id'] : null,
            );
        } catch (\RuntimeException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        if (($validated['assign_compliance'] ?? false) && $profile->user) {
            app(ComplianceMatrixService::class)->evaluateStaff($profile->user);
        }

        if (($validated['send_welcome_email'] ?? false) && ! empty($validated['welcome_email_id'])) {
            SendOnboardingEmailJob::dispatch((int) $validated['welcome_email_id'], $profile->id);
        }

        return redirect()
            ->route('hr.onboarding.show', $checklist->id)
            ->with('success', "Onboarding checklist created with {$checklist->tasks->count()} tasks.");
    }

    /* ================================================================== */
    /*  Task lifecycle */
    /* ================================================================== */

    public function completeTask(CompleteOnboardingTaskRequest $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        $task = $this->lifecycleAccess->visibleOnboardingTask($user, $task);

        $validated = $request->validated();
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $task->checklist_id);
        if (! empty($validated['signed_off_by'])) {
            $this->authorizedChecklistUser($user, (int) $validated['signed_off_by'], $checklist);
        }

        if ($task->sign_off_required && empty($validated['signed_off_by'])) {
            return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');
        }

        $payload = [
            'notes' => $validated['notes'] ?? null,
            'signed_off_by' => $validated['signed_off_by'] ?? null,
        ];

        // The service turns the uploaded file into a gated HrDocument (+ sign-off
        // signature) inside the completion transaction.
        if ($request->hasFile('evidence')) {
            $payload['evidence_file'] = $request->file('evidence');
        }

        try {
            $this->onboardingService->completeTask($task, $user->id, array_filter(
                $payload,
                fn ($value) => $value !== null,
            ));
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Task '{$task->title}' completed.");
    }

    public function uncompleteTask(Request $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $task = $this->lifecycleAccess->visibleOnboardingTask($user, $task);

        $this->onboardingService->uncompleteTask($task);

        return redirect()->back()->with('success', "Task '{$task->title}' reopened.");
    }

    public function updateTask(UpdateOnboardingTaskRequest $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        $task = $this->lifecycleAccess->visibleOnboardingTask($user, $task);

        $validated = $request->validated();
        if (! empty($validated['assigned_to_user_id'])) {
            $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $task->checklist_id);
            $this->authorizedChecklistUser($user, (int) $validated['assigned_to_user_id'], $checklist);
        }

        $this->onboardingService->editTask($task, $validated);

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function storeTask(StoreOnboardingTaskRequest $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $validated = $request->validated();
        if (! empty($validated['assigned_to_user_id'])) {
            $this->authorizedChecklistUser($user, (int) $validated['assigned_to_user_id'], $checklist);
        }

        $this->onboardingService->addTask($checklist, $validated);

        return redirect()->back()->with('success', 'Task added.');
    }

    public function provisionAsset(ProvisionOnboardingAssetRequest $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        $task = $this->lifecycleAccess->visibleOnboardingTask($user, $task);

        $validated = $request->validated();
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $task->checklist_id);
        if (! empty($validated['signed_off_by'])) {
            $this->authorizedChecklistUser($user, (int) $validated['signed_off_by'], $checklist);
        }

        if ($task->sign_off_required && empty($validated['signed_off_by'])) {
            return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');
        }

        $assetIds = $this->lifecycleAccess->authorizedAssetIds($user);
        $asset = ! empty($validated['asset_id'])
            ? Asset::query()->whereIn('id', $assetIds)->findOrFail((int) $validated['asset_id'])
            : $this->onboardingService->autoPickAvailableAsset($validated['category'] ?? null, $assetIds);

        if (! $asset) {
            return redirect()->back()->with('error', 'No available asset to auto-assign — add one or pick a specific asset.');
        }

        try {
            $this->onboardingService->provisionAssetForTask(
                $task,
                $asset,
                $user->id,
                $validated['purpose'] ?? null,
                $validated['signed_off_by'] ?? null,
            );
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Issued {$asset->name} and completed the task.");
    }

    public function destroyTask(Request $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $task = $this->lifecycleAccess->visibleOnboardingTask($user, $task);

        $this->onboardingService->deleteTask($task);

        return redirect()->back()->with('success', 'Task deleted.');
    }

    public function reorderTasks(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $validated = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $this->onboardingService->reorderTasks($checklist, $validated['task_ids']);

        return redirect()->back()->with('success', 'Tasks reordered.');
    }

    /* ================================================================== */
    /*  Checklist lifecycle */
    /* ================================================================== */

    public function completeChecklist(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $this->onboardingService->markChecklistComplete($checklist);

        return redirect()->back()->with('success', 'Onboarding marked complete.');
    }

    public function setChecklistStatus(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $validated = $request->validate([
            'status' => ['required', 'in:pending,in_progress,completed,cancelled,archived'],
        ]);

        $this->onboardingService->setChecklistStatus($checklist, $validated['status']);

        return redirect()->back()->with('success', 'Checklist updated.');
    }

    public function remindChecklist(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $count = $this->sendChecklistReminders($user, $checklist);

        return redirect()->back()->with('success', $count > 0
            ? "Reminder sent to {$count} assignee(s)."
            : 'No outstanding assigned tasks to remind.');
    }

    public function bulkAction(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $validated = $request->validate([
            'action' => ['required', 'in:remind,complete,archive'],
            'checklist_ids' => ['required', 'array', 'min:1'],
            'checklist_ids.*' => ['integer'],
        ]);

        $requestedIds = collect($validated['checklist_ids'])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $checklists = $this->lifecycleAccess->visibleOnboardingChecklists($user)
            ->whereIn('id', $validated['checklist_ids'])
            ->get();
        abort_unless($checklists->count() === $requestedIds->count(), 404);

        foreach ($checklists as $checklist) {
            match ($validated['action']) {
                'remind' => $this->sendChecklistReminders($user, $checklist),
                'complete' => $this->onboardingService->markChecklistComplete($checklist),
                'archive' => $this->onboardingService->setChecklistStatus($checklist, 'archived'),
            };
        }

        $verb = ['remind' => 'reminded', 'complete' => 'marked complete', 'archive' => 'archived'][$validated['action']];

        return redirect()->back()->with('success', $checklists->count()." checklist(s) {$verb}.");
    }

    /** Notify the assignees of every incomplete task on a checklist. */
    private function sendChecklistReminders(User $viewer, HrOnboardingChecklist $checklist): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        $tasks = $checklist->tasks()
            ->where('status', '!=', 'completed')
            ->whereNotNull('assigned_to_user_id')
            ->with('assignedTo')
            ->get();

        foreach ($tasks as $task) {
            $assignee = $task->assignedTo;
            if (! $assignee || ! $this->isAuthorizedChecklistUser($viewer, $assignee, $checklist)) {
                continue;
            }

            $reason = $task->due_date && Carbon::parse($task->due_date)->startOfDay()->lt($today)
                ? 'overdue'
                : 'due_soon';

            try {
                $assignee->notify(new OnboardingTaskDueNotification($task, $reason));
                $sent++;
            } catch (\Throwable $exception) {
                // best-effort
            }
        }

        return $sent;
    }

    public function reassignChecklist(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $validated = $request->validate([
            'owner_id' => ['required', 'integer'],
        ]);

        $newOwner = $this->lifecycleAccess->currentUser($user, (int) $validated['owner_id']);
        abort_unless($this->ownerCanAccessChecklist($newOwner, $checklist), 404);

        [$previousOwnerId, $checklist] = DB::transaction(function () use ($user, $checklist, $newOwner): array {
            $locked = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist, true);
            abort_unless($this->ownerCanAccessChecklist($newOwner, $locked), 404);
            $previousOwnerId = $locked->created_by;
            $locked->update(['created_by' => $newOwner->id]);

            return [$previousOwnerId, $locked->fresh()];
        });

        // The new owner inherits responsibility for driving the checklist —
        // tell them, unless they reassigned it to themselves.
        if ($validated['owner_id'] !== $user->id && $validated['owner_id'] !== $previousOwnerId) {
            try {
                $newOwner->notify(new OnboardingOwnerReassignedNotification($checklist->fresh()->loadMissing('employeeProfile.user')));
            } catch (\Throwable $exception) {
                Log::warning('Failed to send onboarding owner-reassigned notification', [
                    'checklist_id' => $checklist->id,
                    'owner_id' => $validated['owner_id'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return redirect()->back()->with('success', 'Checklist owner reassigned.');
    }

    public function destroy(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklist($user, $checklist);

        $this->onboardingService->setChecklistStatus($checklist, 'archived');

        return redirect()->route('hr.onboarding.index')->with('success', 'Checklist archived.');
    }

    /* ================================================================== */
    /*  Templates */
    /* ================================================================== */

    public function updateTemplates(StoreOnboardingTemplateRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $siteType = trim((string) ($validated['site_type'] ?? ''));
        $siteType = $siteType !== '' ? $siteType : 'all';

        $updated = ! empty($validated['template_id']);
        DB::transaction(function () use ($validated, $siteType, $user, $updated): void {
            $template = $updated
                ? HrOnboardingTemplate::query()->lockForUpdate()->findOrFail((int) $validated['template_id'])
                : null;
            $collision = HrOnboardingTemplate::query()
                ->where('role', $validated['role'])
                ->where('site_type', $siteType)
                ->when($template, fn ($query) => $query->where('id', '!=', $template->id))
                ->lockForUpdate()
                ->exists();
            if ($collision) {
                throw ValidationException::withMessages([
                    'site_type' => 'A template already exists for this role and Site type.',
                ]);
            }

            $attributes = [
                'role' => $validated['role'],
                'site_type' => $siteType,
                'tasks' => $validated['tasks'],
                'is_active' => $validated['is_active'] ?? ($template?->is_active ?? true),
            ];
            if ($template) {
                $template->update($attributes + ['updated_by' => $user->id]);
            } else {
                HrOnboardingTemplate::create($attributes + ['created_by' => $user->id]);
            }
        });

        return redirect()->back()->with('success', $updated
            ? 'Onboarding template updated.'
            : 'Onboarding template created.');
    }

    public function duplicateTemplate(Request $request, HrOnboardingTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $template = DB::transaction(function () use ($template, $user): HrOnboardingTemplate {
            $locked = HrOnboardingTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            // Site-type is part of the unique key, so a copy needs a distinct one.
            $siteType = $locked->site_type === 'all' ? 'copy' : 'all';
            $used = HrOnboardingTemplate::query()
                ->where('role', $locked->role)
                ->lockForUpdate()
                ->pluck('site_type');
            $suffix = 0;
            while ($used->contains($suffix ? "{$siteType}-{$suffix}" : $siteType)) {
                $suffix++;
            }

            HrOnboardingTemplate::create([
                'role' => $locked->role,
                'site_type' => $suffix ? "{$siteType}-{$suffix}" : $siteType,
                'tasks' => $locked->tasks,
                'is_active' => false,
                'created_by' => $user->id,
            ]);

            return $locked;
        });

        return redirect()->back()->with('success', "Duplicated \"{$template->role}\" template.");
    }

    public function setTemplateActive(Request $request, HrOnboardingTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($template, $validated, $user): void {
            $locked = HrOnboardingTemplate::query()->lockForUpdate()->findOrFail($template->getKey());
            $locked->update(['is_active' => $validated['is_active'], 'updated_by' => $user->id]);
        });

        return redirect()->back()->with('success', $validated['is_active'] ? 'Template activated.' : 'Template deactivated.');
    }

    public function destroyTemplate(Request $request, HrOnboardingTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        DB::transaction(function () use ($template): void {
            HrOnboardingTemplate::query()->lockForUpdate()->findOrFail($template->getKey())->delete();
        });

        return redirect()->back()->with('success', 'Template deleted.');
    }

    /* ================================================================== */
    /*  Internal helpers */
    /* ================================================================== */

    private function authorizedChecklistUser(
        User $viewer,
        int $candidateId,
        HrOnboardingChecklist $checklist,
    ): User {
        $candidate = $this->lifecycleAccess->currentUser($viewer, $candidateId);
        abort_unless($this->ownerCanAccessChecklist($candidate, $checklist), 404);

        return $candidate;
    }

    private function isAuthorizedChecklistUser(
        User $viewer,
        User $candidate,
        HrOnboardingChecklist $checklist,
    ): bool {
        try {
            $this->lifecycleAccess->currentUser($viewer, (int) $candidate->id);
        } catch (ModelNotFoundException) {
            return false;
        }

        return $this->ownerCanAccessChecklist($candidate, $checklist);
    }

    private function ownerCanAccessChecklist(User $owner, HrOnboardingChecklist $checklist): bool
    {
        $profile = $checklist->employeeProfile()->first();
        if (! $profile || ! $profile->is_active) {
            return false;
        }

        $siteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $this->lifecycleAccess->canAccessEverySite($owner, $siteIds);
    }

    /** Tasks + header for the quick-peek drawer of one checklist. */
    private function drawerPayload(User $viewer, int $checklistId): ?array
    {
        $checklist = $this->lifecycleAccess->visibleOnboardingChecklists($viewer)
            ->where('id', $checklistId)
            ->with([
                'employeeProfile.user:id,name',
                'employeeProfile.primarySite:id,name',
                'tasks' => fn ($q) => $q->orderBy('sort_order'),
                'tasks.assignedTo:id,name',
            ])
            ->first();

        if (! $checklist) {
            return null;
        }

        $profile = $checklist->employeeProfile;
        $total = $checklist->tasks->count();
        $done = $checklist->tasks->where('status', 'completed')->count();
        $today = now()->startOfDay();

        return [
            'id' => $checklist->id,
            'name' => $profile?->user?->name ?? 'Unknown',
            'role' => $profile?->position_title ?? $profile?->position_role,
            'site' => $profile?->primarySite?->name,
            'pct' => $total > 0 ? (int) round(($done / $total) * 100) : 0,
            'tasks' => $checklist->tasks->map(fn (HrOnboardingTask $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'category' => $task->category ?: 'general',
                'assignee' => $task->assignedTo?->name,
                'sign_off_required' => (bool) $task->sign_off_required,
                'is_completed' => $task->status === 'completed',
                'is_overdue' => $task->status !== 'completed'
                    && $task->due_date
                    && Carbon::parse($task->due_date)->startOfDay()->lt($today),
            ])->values()->all(),
        ];
    }

    private function checklistResource(HrOnboardingChecklist $checklist): array
    {
        $profile = $checklist->employeeProfile;
        $total = (int) ($checklist->tasks_count ?? 0);
        $done = (int) ($checklist->completed_tasks_count ?? 0);
        $isOverdue = ! in_array($checklist->status, self::TERMINAL, true)
            && $checklist->due_date
            && Carbon::parse($checklist->due_date)->startOfDay()->lt(now()->startOfDay());

        return [
            'id' => $checklist->id,
            'status' => $checklist->status,
            'is_overdue' => $isOverdue,
            'template_key' => $checklist->template_key,
            'started_at' => optional($checklist->started_at)->toDateTimeString(),
            'completed_at' => optional($checklist->completed_at)->toDateTimeString(),
            'due_date' => optional($checklist->due_date)->toDateString(),
            'tasks_count' => $total,
            'tasks_completed_count' => $done,
            'owner' => $checklist->creator?->name,
            'employee' => [
                'id' => $profile?->id,
                'name' => $profile?->user?->name ?? 'Unknown',
                'role' => $profile?->position_title ?? $profile?->position_role,
                'site' => $profile?->primarySite?->name,
            ],
        ];
    }

    /** Server-side aggregates over ALL rows (not the current page). */
    private function buildSummary(User $viewer): array
    {
        $base = fn () => $this->lifecycleAccess->visibleOnboardingChecklists($viewer);
        $today = now()->toDateString();

        $statusCounts = $base()
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pending = (int) ($statusCounts['pending'] ?? 0);
        $inProgress = (int) ($statusCounts['in_progress'] ?? 0);
        $completed = (int) ($statusCounts['completed'] ?? 0);

        $overdue = $base()
            ->whereNotIn('status', self::TERMINAL)
            ->whereDate('due_date', '<', $today)
            ->count();

        $dueThisWeek = $base()
            ->whereNotIn('status', self::TERMINAL)
            ->whereBetween('due_date', [$today, now()->addDays(7)->toDateString()])
            ->count();

        $completed30d = $base()
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(30))
            ->count();

        // Average completion across active (non-terminal) checklists.
        $active = $base()
            ->whereNotIn('status', self::TERMINAL)
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->get();
        $avgCompletion = $active->isEmpty()
            ? 0
            : (int) round($active->avg(fn ($c) => $c->tasks_count > 0
                ? ($c->completed_tasks_count / $c->tasks_count) * 100
                : 0));

        return [
            'pending' => $pending,
            'in_progress' => $inProgress,
            'active' => $pending + $inProgress,
            'completed' => $completed,
            'completed_30d' => $completed30d,
            'overdue' => $overdue,
            'due_this_week' => $dueThisWeek,
            'avg_completion' => $avgCompletion,
            'total' => (int) $statusCounts->sum(),
        ];
    }

    /** Overview dashboard lanes, starters strip, and recent activity. */
    private function overviewData(User $viewer): array
    {
        $today = now()->startOfDay();

        $checklistIds = $this->lifecycleAccess->visibleOnboardingChecklists($viewer)
            ->whereNotIn('status', self::TERMINAL)
            ->pluck('id');

        $taskQuery = fn () => HrOnboardingTask::query()
            ->whereIn('checklist_id', $checklistIds)
            ->where('status', '!=', 'completed')
            ->with([
                'assignedTo:id,name',
                'checklist.employeeProfile.user:id,name',
            ]);

        $overdueLane = $taskQuery()
            ->whereDate('due_date', '<', $today->toDateString())
            ->orderBy('due_date')
            ->limit(6)
            ->get()
            ->map(fn (HrOnboardingTask $task) => [
                'id' => $task->id,
                'checklist_id' => $task->checklist_id,
                'task' => $task->title,
                'who' => $task->assignedTo?->name ?? 'Unassigned',
                'employee' => $task->checklist?->employeeProfile?->user?->name ?? 'Unknown',
                'late' => $task->due_date
                    ? Carbon::parse($task->due_date)->startOfDay()->diffInDays($today).'d late'
                    : null,
            ])
            ->values();

        $signoffLane = $taskQuery()
            ->where('sign_off_required', true)
            ->orderBy('due_date')
            ->limit(6)
            ->get()
            ->map(fn (HrOnboardingTask $task) => [
                'id' => $task->id,
                'checklist_id' => $task->checklist_id,
                'task' => $task->title,
                'employee' => $task->checklist?->employeeProfile?->user?->name ?? 'Unknown',
            ])
            ->values();

        $starters = $this->lifecycleAccess->visibleOnboardingChecklists($viewer)
            ->whereNotIn('status', self::TERMINAL)
            ->with(['employeeProfile.user:id,name', 'employeeProfile:id,user_id,position_title,start_date'])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->whereHas('employeeProfile', fn ($q) => $q
                ->whereBetween('start_date', [now()->subDays(2)->toDateString(), now()->addDays(14)->toDateString()]))
            ->get()
            ->sortBy(fn ($c) => $c->employeeProfile?->start_date)
            ->take(6)
            ->map(fn (HrOnboardingChecklist $c) => [
                'id' => $c->id,
                'name' => $c->employeeProfile?->user?->name ?? 'Unknown',
                'role' => $c->employeeProfile?->position_title,
                'date' => optional($c->employeeProfile?->start_date)->toDateString(),
                'done' => (int) $c->completed_tasks_count,
                'total' => (int) $c->tasks_count,
            ])
            ->values();

        return [
            'overdue_tasks' => $overdueLane,
            'signoff_tasks' => $signoffLane,
            'starters' => $starters,
            'activity' => $this->activityFeed($viewer),
        ];
    }

    /** Recent activity derived from real task completions + checklist creations. */
    private function activityFeed(User $viewer): array
    {
        $checklistIds = $this->lifecycleAccess->visibleOnboardingChecklists($viewer)
            ->pluck('id');

        $completions = HrOnboardingTask::query()
            ->whereIn('checklist_id', $checklistIds)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->with(['completedBy:id,name', 'checklist.employeeProfile.user:id,name'])
            ->orderByDesc('completed_at')
            ->limit(8)
            ->get()
            ->map(fn (HrOnboardingTask $task) => [
                'who' => $task->completedBy?->name ?? 'Someone',
                'action' => "completed \"{$task->title}\" for "
                    .($task->checklist?->employeeProfile?->user?->name ?? 'a new starter'),
                'when' => optional($task->completed_at)->diffForHumans(),
                'at' => optional($task->completed_at)->toDateTimeString(),
                'tone' => 'success',
            ]);

        $created = $this->lifecycleAccess->visibleOnboardingChecklists($viewer)
            ->with(['creator:id,name', 'employeeProfile.user:id,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (HrOnboardingChecklist $c) => [
                'who' => $c->creator?->name ?? 'Someone',
                'action' => 'started onboarding for '
                    .($c->employeeProfile?->user?->name ?? 'a new starter'),
                'when' => optional($c->created_at)->diffForHumans(),
                'at' => optional($c->created_at)->toDateTimeString(),
                'tone' => 'info',
            ]);

        return $completions->concat($created)
            ->sortByDesc('at')
            ->take(8)
            ->values()
            ->all();
    }

    private function templatePayload(): Collection
    {
        return HrOnboardingTemplate::query()
            ->orderBy('role')
            ->orderBy('site_type')
            ->get()
            ->map(function (HrOnboardingTemplate $template) {
                $tasks = collect($template->tasks ?? [])
                    ->map(fn ($task, $index) => [
                        'category' => $task['category'] ?? 'general',
                        'title' => $task['title'] ?? '',
                        'description' => $task['description'] ?? null,
                        'is_required' => (bool) ($task['is_required'] ?? true),
                        'sort_order' => (int) ($task['sort_order'] ?? ($index + 1)),
                        'assigned_to_role' => $task['assigned_to_role'] ?? null,
                        'sign_off_required' => (bool) ($task['sign_off_required'] ?? false),
                        'course_code' => $task['course_code'] ?? null,
                    ])
                    ->values();

                return [
                    'id' => $template->id,
                    'role' => $template->role,
                    'site_type' => $template->site_type,
                    'is_active' => (bool) $template->is_active,
                    'tasks' => $tasks->all(),
                    'task_count' => $tasks->count(),
                    'chips' => $tasks->pluck('category')
                        ->map(fn ($c) => ucfirst((string) $c))
                        ->unique()
                        ->take(5)
                        ->values()
                        ->all(),
                    'updated_at' => optional($template->updated_at)->toDateTimeString(),
                ];
            })
            ->values();
    }

    private function emailPayload(User $viewer): array
    {
        $templates = HrOnboardingEmail::query()
            ->orderBy('send_days_before_start')
            ->get()
            ->map(fn (HrOnboardingEmail $email) => [
                'id' => $email->id,
                'template_name' => $email->template_name,
                'subject' => $email->subject,
                'body' => $email->body,
                'send_days_before_start' => (int) $email->send_days_before_start,
                'is_active' => (bool) $email->is_active,
            ])
            ->values();

        $log = HrOnboardingEmailLog::query()
            ->whereIn('employee_profile_id', $this->lifecycleAccess->onboardingProfiles($viewer)->select('id'))
            ->with([
                'onboardingEmail:id,template_name',
                'employeeProfile.user:id,name',
            ])
            ->orderByDesc('created_at')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'template' => $row->onboardingEmail?->template_name ?? 'Email',
                'to' => $row->employeeProfile?->user?->name ?? 'Unknown',
                'status' => $row->status,
                'when' => optional($row->sent_at ?? $row->created_at)->diffForHumans(),
            ])
            ->values();

        return [
            'templates' => $templates->all(),
            'log' => $log->all(),
        ];
    }

    /** Active employee profiles without an in-flight checklist (wizard picks). */
    private function eligibleEmployees(User $viewer): Collection
    {
        $existingProfileIds = $this->lifecycleAccess->visibleOnboardingChecklists($viewer)
            ->whereIn('status', ['pending', 'in_progress'])
            ->pluck('employee_profile_id');

        return $this->lifecycleAccess->onboardingProfiles($viewer)
            ->with(['user:id,name,email', 'primarySite:id,name,type'])
            ->whereNotIn('id', $existingProfileIds)
            ->get()
            ->map(fn (HrEmployeeProfile $profile) => [
                'id' => $profile->id,
                'name' => $profile->user?->name ?? 'Unknown',
                'email' => $profile->user?->email,
                'position_title' => $profile->position_title,
                'position_role' => $profile->position_role,
                'primary_site_name' => $profile->primarySite?->name,
                'primary_site_type' => $profile->primarySite?->type,
                'start_date' => optional($profile->start_date)->toDateString(),
            ])
            ->values();
    }

    private function emailTemplateOptions(): Collection
    {
        return HrOnboardingEmail::query()
            ->where('is_active', true)
            ->orderBy('send_days_before_start')
            ->get(['id', 'template_name', 'send_days_before_start'])
            ->map(fn (HrOnboardingEmail $email) => [
                'id' => $email->id,
                'template_name' => $email->template_name,
                'send_days_before_start' => $email->send_days_before_start,
            ])
            ->values();
    }

    /** Current users visible to the viewer for owner / reassign pickers. */
    private function currentUserOptions(User $viewer): Collection
    {
        return $this->lifecycleAccess->currentUsers($viewer)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])
            ->filter(fn ($u) => $u['id'] && $u['name'])
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /** Site + manager options for the wizard's "+ New hire" branch. */
    private function newHireOptions(User $viewer): array
    {
        $sites = Site::query()
            ->whereIn('id', $this->lifecycleAccess->accessibleSiteIds($viewer))
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type])
            ->values();

        return [
            'sites' => $sites->all(),
            'managers' => $this->currentUserOptions($viewer)->all(),
            'roles' => $this->roleOptions(),
            'employment_types' => ['full_time', 'part_time', 'casual', 'fixed_term'],
        ];
    }

    /**
     * Active training courses (code + title) for the template editor's per-task
     * course picker — the code is what enrolInductionCourses() matches on.
     */
    private function courseOptions(): Collection
    {
        if (! Schema::hasTable('hr_courses')) {
            return collect();
        }

        return HrCourse::query()
            ->active()
            ->whereNotNull('code')
            ->orderBy('title')
            ->get(['code', 'title', 'is_mandatory'])
            ->map(fn ($c) => [
                'code' => $c->code,
                'title' => $c->title,
                'is_mandatory' => (bool) $c->is_mandatory,
            ])
            ->values();
    }

    /** @return array<int, string> */
    private function roleOptions(): array
    {
        return [
            'support_worker',
            'team_lead',
            'coordinator',
            'provider_manager',
            'admin',
        ];
    }
}
