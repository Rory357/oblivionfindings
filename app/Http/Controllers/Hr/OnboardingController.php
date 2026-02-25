<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\OnboardingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly OnboardingService $onboardingService,
    ) {}

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
                'creator:id,name',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($query) => $query->where('status', 'completed'),
            ])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->whereHas('employeeProfile.user', fn ($users) =>
                $users->where('name', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $baseQuery = HrOnboardingChecklist::query()
            ->where('tenant_id', $tenantId);

        $summary = [
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'in_progress' => (clone $baseQuery)->where('status', 'in_progress')->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
            'total' => (clone $baseQuery)->count(),
        ];

        $templates = HrOnboardingTemplate::query()
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
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => $template->id,
                    'role' => $template->role,
                    'site_type' => $template->site_type,
                    'is_active' => (bool) $template->is_active,
                    'tasks' => $tasks,
                    'task_count' => count($tasks),
                    'updated_at' => optional($template->updated_at)->toDateTimeString(),
                ];
            })
            ->values();

        return Inertia::render('hr/onboarding/index', [
            'checklists' => $checklists,
            'summary' => $summary,
            'templates' => $templates,
            'templateRoleOptions' => [
                'support_worker',
                'team_lead',
                'coordinator',
                'provider_manager',
                'admin',
            ],
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

    public function show(Request $request, HrOnboardingChecklist $checklist)
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

        return Inertia::render('hr/onboarding/show', [
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

        $tenantId = $this->resolveHrTenantIdForUser($user);

        $existingProfileIds = HrOnboardingChecklist::query()
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
            ]);

        return Inertia::render('hr/onboarding/create', [
            'employees' => $employees,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
        ]);

        $profile = HrEmployeeProfile::query()->findOrFail((int) $validated['employee_profile_id']);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

        $existing = HrOnboardingChecklist::query()
            ->where('employee_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($existing) {
            return redirect()->back()->with('error', 'An active onboarding checklist already exists for this employee.');
        }

        try {
            $checklist = $this->onboardingService->generateChecklist($profile, $user->id);
        } catch (\RuntimeException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Onboarding checklist created with {$checklist->tasks->count()} tasks.");
    }

    public function completeTask(Request $request, HrOnboardingTask $task)
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
            $this->onboardingService->completeTask($task, $user->id, $validated);
        } catch (\LogicException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', "Task '{$task->title}' completed.");
    }

    public function updateTemplates(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:hr_onboarding_templates,id'],
            'role' => ['required', 'string', 'max:100'],
            'site_type' => ['nullable', 'string', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.category' => ['required', 'string', 'max:100'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string', 'max:2000'],
            'tasks.*.is_required' => ['required', 'boolean'],
            'tasks.*.sort_order' => ['nullable', 'integer', 'min:1'],
            'tasks.*.assigned_to_role' => ['nullable', 'string', 'max:100'],
            'tasks.*.sign_off_required' => ['sometimes', 'boolean'],
        ]);

        $siteType = trim((string) ($validated['site_type'] ?? ''));
        $siteType = $siteType !== '' ? $siteType : 'all';

        if (! empty($validated['template_id'])) {
            $template = HrOnboardingTemplate::query()
                ->where('id', $validated['template_id'])
                ->firstOrFail();

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
}
