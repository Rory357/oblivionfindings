<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Jobs\SendOnboardingEmailJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingEmail;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Domain\Hr\Services\OnboardingService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\CompleteOnboardingTaskRequest;
use App\Http\Requests\Hr\StoreOnboardingChecklistRequest;
use App\Http\Requests\Hr\StoreOnboardingTaskRequest;
use App\Http\Requests\Hr\StoreOnboardingTemplateRequest;
use App\Http\Requests\Hr\UpdateOnboardingTaskRequest;
use App\Models\AssetAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    use ResolvesHrTenant;

    /** Terminal statuses that should not appear as "active" work. */
    private const TERMINAL = ['completed', 'cancelled', 'archived'];

    public function __construct(
        private readonly OnboardingService $onboardingService,
    ) {}

    /* ================================================================== */
    /*  Hub                                                                */
    /* ================================================================== */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $checklists = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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
            ->when($search !== '', fn ($query) => $query->whereHas('employeeProfile.user', fn ($users) =>
                $users->where('name', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $checklists->through(fn (HrOnboardingChecklist $checklist) => $this->checklistResource($checklist));

        // Quick-peek drawer: only hydrated when the row drawer is open (partial
        // reload with ?drawer={id}), so the hub stays light on first paint.
        $drawerId = $request->query('drawer');
        $drawerChecklist = $drawerId
            ? $this->drawerPayload($tenantId, (int) $drawerId)
            : null;

        return Inertia::render('hr/onboarding/index', [
            'checklists' => $checklists,
            'drawerChecklist' => $drawerChecklist,
            'summary' => $this->buildSummary($tenantId),
            'overview' => $this->overviewData($tenantId),
            'templates' => $this->templatePayload($tenantId),
            'emails' => $this->emailPayload($tenantId),
            'employees' => $this->eligibleEmployees($tenantId),
            'emailTemplates' => $this->emailTemplateOptions($tenantId),
            'owners' => $this->tenantUserOptions($tenantId),
            'newHireOptions' => $this->newHireOptions($tenantId),
            'templateRoleOptions' => $this->roleOptions(),
            'courseOptions' => $this->courseOptions($tenantId),
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

    public function export(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $rows = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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
            fputcsv($out, ['Employee', 'Role', 'Site', 'Template', 'Status', 'Tasks done', 'Tasks total', 'Owner', 'Due date']);
            foreach ($rows as $c) {
                fputcsv($out, [
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

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
            'owners' => $this->tenantUserOptions($tenantId),
            'provisionableAssets' => $this->provisionableAssets(),
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    /** Active company assets not currently issued — the IT-provisioning picks. */
    private function provisionableAssets(): \Illuminate\Support\Collection
    {
        $assignedIds = AssetAssignment::query()->whereNull('released_at')->pluck('asset_id');

        return \App\Models\Asset::query()
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
    /*  Start onboarding (existing employee + new hire — one path)         */
    /* ================================================================== */

    public function store(StoreOnboardingChecklistRequest $request, EmployeeIntakeService $intake)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $validated = $request->validated();

        if (($validated['hire_mode'] ?? 'existing') === 'new') {
            // Converged new-hire path: create the person via the single intake
            // door (no onboarding yet — we generate it explicitly below so the
            // chosen template / compliance / welcome-email options are honoured).
            $roleName = $validated['role'] ?? 'support_worker';
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
                tenantId: $tenantId,
                startOnboarding: false,
                sendInvite: false,
                source: 'onboarding_wizard',
            );
        } else {
            $profile = HrEmployeeProfile::query()->findOrFail((int) $validated['employee_profile_id']);
            $this->assertHrTenantAccess($tenantId, $profile->tenant_id);
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
    /*  Task lifecycle                                                     */
    /* ================================================================== */

    public function completeTask(CompleteOnboardingTaskRequest $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $checklist = $this->taskChecklist($task, $tenantId);

        $validated = $request->validated();

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
        $this->taskChecklist($task, $this->resolveHrTenantIdForUser($user));

        $this->onboardingService->uncompleteTask($task);

        return redirect()->back()->with('success', "Task '{$task->title}' reopened.");
    }

    public function updateTask(UpdateOnboardingTaskRequest $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        $this->taskChecklist($task, $this->resolveHrTenantIdForUser($user));

        $this->onboardingService->editTask($task, $request->validated());

        return redirect()->back()->with('success', 'Task updated.');
    }

    public function storeTask(StoreOnboardingTaskRequest $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

        $this->onboardingService->addTask($checklist, $request->validated());

        return redirect()->back()->with('success', 'Task added.');
    }

    public function provisionAsset(\App\Http\Requests\Hr\ProvisionOnboardingAssetRequest $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        $this->taskChecklist($task, $this->resolveHrTenantIdForUser($user));

        $validated = $request->validated();

        if ($task->sign_off_required && empty($validated['signed_off_by'])) {
            return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');
        }

        $asset = ! empty($validated['asset_id'])
            ? \App\Models\Asset::findOrFail((int) $validated['asset_id'])
            : $this->onboardingService->autoPickAvailableAsset($validated['category'] ?? null);

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
        $this->taskChecklist($task, $this->resolveHrTenantIdForUser($user));

        $this->onboardingService->deleteTask($task);

        return redirect()->back()->with('success', 'Task deleted.');
    }

    public function reorderTasks(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

        $validated = $request->validate([
            'task_ids' => ['required', 'array', 'min:1'],
            'task_ids.*' => ['integer'],
        ]);

        $this->onboardingService->reorderTasks($checklist, $validated['task_ids']);

        return redirect()->back()->with('success', 'Tasks reordered.');
    }

    /* ================================================================== */
    /*  Checklist lifecycle                                                */
    /* ================================================================== */

    public function completeChecklist(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

        $this->onboardingService->markChecklistComplete($checklist);

        return redirect()->back()->with('success', 'Onboarding marked complete.');
    }

    public function setChecklistStatus(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

        $count = $this->sendChecklistReminders($checklist);

        return redirect()->back()->with('success', $count > 0
            ? "Reminder sent to {$count} assignee(s)."
            : 'No outstanding assigned tasks to remind.');
    }

    public function bulkAction(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'action' => ['required', 'in:remind,complete,archive'],
            'checklist_ids' => ['required', 'array', 'min:1'],
            'checklist_ids.*' => ['integer'],
        ]);

        $checklists = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $validated['checklist_ids'])
            ->get();

        foreach ($checklists as $checklist) {
            match ($validated['action']) {
                'remind' => $this->sendChecklistReminders($checklist),
                'complete' => $this->onboardingService->markChecklistComplete($checklist),
                'archive' => $this->onboardingService->setChecklistStatus($checklist, 'archived'),
            };
        }

        $verb = ['remind' => 'reminded', 'complete' => 'marked complete', 'archive' => 'archived'][$validated['action']];

        return redirect()->back()->with('success', $checklists->count()." checklist(s) {$verb}.");
    }

    /** Notify the assignees of every incomplete task on a checklist. */
    private function sendChecklistReminders(HrOnboardingChecklist $checklist): int
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
            if (! $assignee) {
                continue;
            }

            $reason = $task->due_date && Carbon::parse($task->due_date)->startOfDay()->lt($today)
                ? 'overdue'
                : 'due_soon';

            try {
                $assignee->notify(new \App\Domain\Hr\Notifications\OnboardingTaskDueNotification($task, $reason));
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
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $checklist->update(['created_by' => $validated['owner_id']]);

        return redirect()->back()->with('success', 'Checklist owner reassigned.');
    }

    public function destroy(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

        $checklist->delete();

        return redirect()->route('hr.onboarding.index')->with('success', 'Checklist deleted.');
    }

    /* ================================================================== */
    /*  Templates                                                          */
    /* ================================================================== */

    public function updateTemplates(StoreOnboardingTemplateRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $validated = $request->validated();

        $siteType = trim((string) ($validated['site_type'] ?? ''));
        $siteType = $siteType !== '' ? $siteType : 'all';

        if (! empty($validated['template_id'])) {
            $template = HrOnboardingTemplate::query()->findOrFail($validated['template_id']);
            $this->assertHrTenantAccess($tenantId, $template->tenant_id);

            $template->update([
                'role' => $validated['role'],
                'site_type' => $siteType,
                'tasks' => $validated['tasks'],
                'is_active' => $validated['is_active'] ?? $template->is_active,
                'updated_by' => $user->id,
            ]);

            return redirect()->back()->with('success', 'Onboarding template updated.');
        }

        HrOnboardingTemplate::create([
            'tenant_id' => $tenantId,
            'role' => $validated['role'],
            'site_type' => $siteType,
            'tasks' => $validated['tasks'],
            'is_active' => $validated['is_active'] ?? true,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Onboarding template created.');
    }

    public function duplicateTemplate(Request $request, HrOnboardingTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $template->tenant_id);

        // Site-type is part of the unique key, so a copy needs a distinct one.
        $siteType = $template->site_type === 'all' ? 'copy' : 'all';
        $suffix = 0;
        while (HrOnboardingTemplate::where('tenant_id', $tenantId)
            ->where('role', $template->role)
            ->where('site_type', $suffix ? "{$siteType}-{$suffix}" : $siteType)
            ->exists()) {
            $suffix++;
        }

        HrOnboardingTemplate::create([
            'tenant_id' => $tenantId,
            'role' => $template->role,
            'site_type' => $suffix ? "{$siteType}-{$suffix}" : $siteType,
            'tasks' => $template->tasks,
            'is_active' => false,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', "Duplicated \"{$template->role}\" template.");
    }

    public function setTemplateActive(Request $request, HrOnboardingTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $template->tenant_id);

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);
        $template->update(['is_active' => $validated['is_active'], 'updated_by' => $user->id]);

        return redirect()->back()->with('success', $validated['is_active'] ? 'Template activated.' : 'Template deactivated.');
    }

    public function destroyTemplate(Request $request, HrOnboardingTemplate $template)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $template->tenant_id);

        $template->delete();

        return redirect()->back()->with('success', 'Template deleted.');
    }

    /* ================================================================== */
    /*  Internal helpers                                                   */
    /* ================================================================== */

    private function taskChecklist(HrOnboardingTask $task, int $tenantId): HrOnboardingChecklist
    {
        $checklist = $task->checklist;
        abort_unless($checklist, 404);
        $this->assertHrTenantAccess($tenantId, $checklist->tenant_id);

        return $checklist;
    }

    /** Tasks + header for the quick-peek drawer of one checklist. */
    private function drawerPayload(int $tenantId, int $checklistId): ?array
    {
        $checklist = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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
    private function buildSummary(int $tenantId): array
    {
        $base = fn () => HrOnboardingChecklist::query()->where('tenant_id', $tenantId);
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
    private function overviewData(int $tenantId): array
    {
        $today = now()->startOfDay();

        $checklistIds = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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

        $starters = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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
            'activity' => $this->activityFeed($tenantId),
        ];
    }

    /** Recent activity derived from real task completions + checklist creations. */
    private function activityFeed(int $tenantId): array
    {
        $checklistIds = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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

        $created = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
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

    private function templatePayload(int $tenantId): \Illuminate\Support\Collection
    {
        return HrOnboardingTemplate::query()
            ->where('tenant_id', $tenantId)
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

    private function emailPayload(int $tenantId): array
    {
        $templates = HrOnboardingEmail::query()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
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

        $log = \App\Domain\Hr\Models\HrOnboardingEmailLog::query()
            ->whereHas('employeeProfile', fn ($q) => $q->where('tenant_id', $tenantId))
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
    private function eligibleEmployees(int $tenantId): \Illuminate\Support\Collection
    {
        $existingProfileIds = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->pluck('employee_profile_id');

        return HrEmployeeProfile::query()
            ->with(['user:id,name,email', 'primarySite:id,name,type'])
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
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

    private function emailTemplateOptions(int $tenantId): \Illuminate\Support\Collection
    {
        return HrOnboardingEmail::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('send_days_before_start')
            ->get(['id', 'template_name', 'send_days_before_start'])
            ->map(fn (HrOnboardingEmail $email) => [
                'id' => $email->id,
                'template_name' => $email->template_name,
                'send_days_before_start' => $email->send_days_before_start,
            ])
            ->values();
    }

    /** Users in the tenant for owner / reassign pickers. */
    private function tenantUserOptions(int $tenantId): \Illuminate\Support\Collection
    {
        return HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->get()
            ->map(fn (HrEmployeeProfile $p) => [
                'id' => $p->user_id,
                'name' => $p->user?->name,
            ])
            ->filter(fn ($u) => $u['id'] && $u['name'])
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /** Site + manager options for the wizard's "+ New hire" branch. */
    private function newHireOptions(int $tenantId): array
    {
        $sites = \App\Models\Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'type'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type])
            ->values();

        return [
            'sites' => $sites->all(),
            'managers' => $this->tenantUserOptions($tenantId)->all(),
            'roles' => $this->roleOptions(),
            'employment_types' => ['full_time', 'part_time', 'casual', 'fixed_term'],
        ];
    }

    /**
     * Active training courses (code + title) for the template editor's per-task
     * course picker — the code is what enrolInductionCourses() matches on.
     */
    private function courseOptions(int $tenantId): \Illuminate\Support\Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_courses')) {
            return collect();
        }

        return \App\Domain\Hr\Models\HrCourse::query()
            ->forTenant($tenantId)
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
