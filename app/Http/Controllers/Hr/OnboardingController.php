<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTask;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Services\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboardingService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — checklists list                                            */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);

        $tenantId = null;
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));

        $checklists = HrOnboardingChecklist::query()
            ->with([
                'employeeProfile.user:id,name,email',
                'creator:id,name',
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn ($q) => $q->where('status', 'completed'),
            ])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->whereHas('employeeProfile.user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
            ))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        // Summary
        $baseQuery = HrOnboardingChecklist::query();
        $summary = [
            'pending'     => (clone $baseQuery)->where('status', 'pending')->count(),
            'in_progress' => (clone $baseQuery)->where('status', 'in_progress')->count(),
            'completed'   => (clone $baseQuery)->where('status', 'completed')->count(),
            'total'       => (clone $baseQuery)->count(),
        ];

        return Inertia::render('hr/onboarding/index', [
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

    /* ------------------------------------------------------------------ */
    /*  Show — checklist detail with tasks                                 */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrOnboardingChecklist $checklist)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.view'), 403);

        $checklist->load([
            'employeeProfile.user:id,name,email',
            'employeeProfile.primarySite:id,name',
            'tasks' => fn ($q) => $q->orderBy('sort_order'),
            'tasks.assignedTo:id,name',
            'tasks.completedBy:id,name',
            'creator:id,name',
        ]);

        $progress = $this->onboardingService->getProgress($checklist);

        return Inertia::render('hr/onboarding/show', [
            'checklist' => $checklist,
            'progress' => $progress,
            'can' => [
                'manage' => $user->canDo('hr.onboarding.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create — show form to create a checklist                           */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $tenantId = $user->tenant_id ?? $user->organization_id ?? 1;

        // Get employees without active checklists
        $existingProfileIds = HrOnboardingChecklist::whereIn('status', ['pending', 'in_progress'])
            ->pluck('employee_profile_id');

        $employees = HrEmployeeProfile::with('user:id,name,email')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotIn('id', $existingProfileIds)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->user?->name ?? 'Unknown',
                'email' => $p->user?->email,
                'position_title' => $p->position_title,
            ]);

        return Inertia::render('hr/onboarding/create', [
            'employees' => $employees,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create a checklist from template for an employee           */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $validated = $request->validate([
            'employee_profile_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
        ]);

        $profile = HrEmployeeProfile::where('id', $validated['employee_profile_id'])
            ->firstOrFail();

        // Check if an active checklist already exists
        $existing = HrOnboardingChecklist::where('employee_profile_id', $profile->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($existing) {
            return redirect()->back()->with('error', 'An active onboarding checklist already exists for this employee.');
        }

        try {
            $checklist = $this->onboardingService->generateChecklist($profile, $user->id);
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Onboarding checklist created with {$checklist->tasks->count()} tasks.");
    }

    /* ------------------------------------------------------------------ */
    /*  Complete Task                                                       */
    /* ------------------------------------------------------------------ */

    public function completeTask(Request $request, HrOnboardingTask $task)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        // Verify task belongs to tenant via checklist
        $checklist = $task->checklist;
        abort_unless($checklist, 404);

        $validated = $request->validate([
            'evidence_path'  => ['nullable', 'string', 'max:500'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'signed_off_by'  => ['nullable', 'integer', 'exists:users,id'],
        ]);

        // If sign-off required but no signed_off_by provided, reject
        if ($task->sign_off_required && empty($validated['signed_off_by'])) {
            return redirect()->back()->with('error', 'This task requires sign-off. Please specify the sign-off user.');
        }

        try {
            $this->onboardingService->completeTask($task, $user->id, $validated);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', "Task '{$task->title}' completed.");
    }

    /* ------------------------------------------------------------------ */
    /*  Update Templates — manage onboarding templates                     */
    /* ------------------------------------------------------------------ */

    public function updateTemplates(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.onboarding.manage'), 403);

        $validated = $request->validate([
            'template_id' => ['nullable', 'integer', 'exists:hr_onboarding_templates,id'],
            'role'         => ['required', 'string', 'max:100'],
            'site_type'    => ['nullable', 'string', 'max:100'],
            'is_active'    => ['sometimes', 'boolean'],
            'tasks'        => ['required', 'array', 'min:1'],
            'tasks.*.category'         => ['required', 'string', 'max:100'],
            'tasks.*.title'            => ['required', 'string', 'max:255'],
            'tasks.*.description'      => ['nullable', 'string', 'max:2000'],
            'tasks.*.is_required'      => ['required', 'boolean'],
            'tasks.*.sort_order'       => ['nullable', 'integer', 'min:1'],
            'tasks.*.assigned_to_role' => ['nullable', 'string', 'max:100'],
            'tasks.*.sign_off_required' => ['sometimes', 'boolean'],
        ]);

        if ($validated['template_id']) {
            // Update existing template
            $template = HrOnboardingTemplate::where('id', $validated['template_id'])
                ->firstOrFail();

            $template->update([
                'role'       => $validated['role'],
                'site_type'  => $validated['site_type'] ?? null,
                'tasks'      => $validated['tasks'],
                'is_active'  => $validated['is_active'] ?? $template->is_active,
                'updated_by' => $user->id,
            ]);

            return redirect()->back()->with('success', 'Onboarding template updated.');
        }

        // Create new template
        HrOnboardingTemplate::create([
            'tenant_id'  => $user->tenant_id,
            'role'       => $validated['role'],
            'site_type'  => $validated['site_type'] ?? null,
            'tasks'      => $validated['tasks'],
            'is_active'  => $validated['is_active'] ?? true,
            'created_by' => $user->id,
        ]);

        return redirect()->back()->with('success', 'Onboarding template created.');
    }
}
