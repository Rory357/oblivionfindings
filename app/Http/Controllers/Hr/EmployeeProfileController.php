<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEmployeeSkill;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Domain\Hr\Notifications\EmployeeInviteNotification;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Domain\Hr\Services\OrgChartService;
use App\Domain\Hr\Services\PositionService;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreEmployeeRequest;
use App\Http\Requests\Hr\UpdateEmployeeProfileRequest;
use App\Models\ProcedureAcknowledgement;
use App\Models\Role;
use App\Models\SafeWorkProcedure;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class EmployeeProfileController extends Controller
{
    use ResolvesHrTenant;

    /* ------------------------------------------------------------------ */
    /*  Index — paginated employee list */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status'); // 'active', 'inactive', or null for all
        $siteId = $request->query('site_id');
        $department = $request->query('department');
        $employmentType = $request->query('employment_type');
        $joined = $request->query('joined');       // '30' = hired within the last 30 days
        $probation = $request->query('probation'); // '1' = currently on probation

        // Server-side sort. Whitelist column → real (qualified) column so the
        // table headers can sort by any displayed field across all pages.
        $sortColumns = [
            'name' => 'users.name',
            'employee_number' => 'hr_employee_profiles.employee_number',
            'position' => 'hr_employee_profiles.position_title',
            'department' => 'hr_employee_profiles.department',
            'type' => 'hr_employee_profiles.employment_type',
            'site' => 'sites.name',
            'start' => 'hr_employee_profiles.start_date',
            'status' => 'hr_employee_profiles.is_active',
        ];
        $sortKey = (string) $request->query('sort', 'name');
        $sortColumn = $sortColumns[$sortKey] ?? 'users.name';
        $sortDir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $profiles = User::query()
            ->staff()
            ->select('users.*')
            // LEFT joins purely for sorting by profile / site columns (1:1, so no
            // row multiplication); the soft-delete guard keeps the join a true
            // LEFT (users with no live profile still list with NULL sort keys).
            ->leftJoin('hr_employee_profiles', function ($join) {
                $join->on('hr_employee_profiles.user_id', '=', 'users.id')
                    ->whereNull('hr_employee_profiles.deleted_at');
            })
            ->leftJoin('sites', 'sites.id', '=', 'hr_employee_profiles.primary_site_id')
            ->with([
                'hrEmployeeProfile.primarySite:id,name',
            ])
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            })
            )
            ->when($status === 'active', fn ($q) => $q->where(function ($statusQuery) {
                $statusQuery
                    ->whereDoesntHave('hrEmployeeProfile')
                    ->orWhereHas('hrEmployeeProfile', fn ($profile) => $profile->where('is_active', true));
            })
            )
            ->when($status === 'inactive', fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($profile) => $profile->where('is_active', false))
            )
            ->when($siteId, fn ($q) => $q->whereHas('hrEmployeeProfile', function ($profileQuery) use ($siteId) {
                $profileQuery->where(function ($siteQuery) use ($siteId) {
                    $siteQuery
                        ->where('primary_site_id', (int) $siteId)
                        ->orWhereJsonContains('secondary_site_ids', (int) $siteId);
                });
            })
            )
            ->when($department, fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($p) => $p->where('department_id', (int) $department))
            )
            ->when($employmentType, fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($p) => $p->where('employment_type', $employmentType))
            )
            ->when($joined === '30', fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($p) => $p
                ->where('is_active', true)
                ->where('start_date', '>=', now()->subDays(30)))
            )
            ->when($probation, fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($p) => $p
                ->where('is_active', true)
                ->whereNotNull('probation_end_date')
                ->where('probation_end_date', '>=', now()))
            )
            ->orderBy($sortColumn, $sortDir)
            ->orderBy('users.name')
            ->paginate(20)
            ->through(function (User $staffUser) {
                $profile = $staffUser->hrEmployeeProfile;
                $primarySite = $profile?->primarySite;

                return [
                    'id' => $staffUser->id,
                    'profile_id' => $profile?->id,
                    'employee_number' => $profile?->employee_number,
                    'position_title' => $profile?->position_title,
                    'employment_type' => $profile?->employment_type,
                    'department' => $profile?->department,
                    'is_active' => $profile ? (bool) $profile->is_active : true,
                    'start_date' => $profile?->start_date?->toDateString(),
                    // Re-hire wizard prefill — only meaningful (and only sent)
                    // for former employees.
                    'end_date' => $profile && ! $profile->is_active ? $profile->end_date?->toDateString() : null,
                    'position_role' => $profile && ! $profile->is_active ? $profile->position_role : null,
                    'hours_per_week' => $profile && ! $profile->is_active && $profile->hours_per_week !== null
                        ? (float) $profile->hours_per_week
                        : null,
                    'employment_history' => $profile && ! $profile->is_active
                        ? ($profile->employment_history ?? [])
                        : null,
                    // Directory-tab card fields (single source — the standalone directory is folded in).
                    'preferred_name' => $profile?->preferred_name,
                    'profile_photo_path' => $profile?->profile_photo_path,
                    'work_email' => $profile?->work_email,
                    'phone' => $profile?->work_phone,
                    'user' => [
                        'id' => $staffUser->id,
                        'name' => $staffUser->name,
                        'email' => $staffUser->email,
                    ],
                    'primary_site' => $primarySite ? [
                        'id' => $primarySite->id,
                        'name' => $primarySite->name,
                    ] : null,
                ];
            })
            ->withQueryString();

        $sites = Site::orderBy('name')
            ->get(['id', 'name']);

        // Summary stats for mini dashboard
        $activeCount = HrEmployeeProfile::where('is_active', true)->count();
        $inactiveCount = HrEmployeeProfile::where('is_active', false)->count();
        $newHires = HrEmployeeProfile::where('is_active', true)
            ->where('start_date', '>=', now()->subDays(30))
            ->count();
        $onProbation = HrEmployeeProfile::where('is_active', true)
            ->whereNotNull('probation_end_date')
            ->where('probation_end_date', '>=', now())
            ->count();
        $complianceAlerts = HrStaffComplianceStatus::whereIn('status', ['expired', 'expiring_soon'])->count();
        // Pending invites — active employee profiles whose login is not active yet.
        $pendingInvites = User::query()->staff()
            ->whereNull('approved_at')
            ->whereNull('last_login_at')
            ->whereHas('hrEmployeeProfile', fn ($p) => $p
                ->where('tenant_id', $tenantId)
                ->where('is_active', true))
            ->count();

        // Employment type breakdown
        $typeCounts = HrEmployeeProfile::where('is_active', true)
            ->selectRaw('employment_type, count(*) as count')
            ->groupBy('employment_type')
            ->pluck('count', 'employment_type')
            ->toArray();

        // Departments list for filter (from managed departments table)
        $departments = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $user->tenant_id)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Option data for the Add-Employee wizard (manager-only surface).
        $canManage = $user->canDo('hr.employees.manage');
        $formData = $canManage ? [
            'positions' => HrPosition::query()
                ->where(fn ($q) => $q->where('tenant_id', $user->tenant_id)->orWhereNull('tenant_id'))
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])
                ->values(),
            'managers' => User::query()->staff()->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->values(),
            'roles' => Role::query()->orderBy('name')->get(['name'])
                ->map(fn ($r) => ['value' => $r->name, 'label' => ucwords(str_replace('_', ' ', $r->name))])
                ->values(),
            'employmentTypes' => collect(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])
                ->map(fn ($v) => ['value' => $v, 'label' => ucwords(str_replace('_', ' ', $v))])
                ->values(),
        ] : null;

        // --- Positions tab (folds /hr/positions; namespaced filters + paginator) ---
        // Users carry no tenant_id column, so all folded tabs reuse the resolved
        // HR tenant from the start of the request.
        $posSearch = trim((string) $request->query('pq', ''));
        $posDepartment = $request->query('pdepartment');
        $posStatus = $request->query('pstatus');

        $positions = HrPosition::forTenant($tenantId)
            ->when($posStatus === 'active', fn ($q) => $q->where('is_active', true))
            ->when($posStatus === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($posDepartment, fn ($q) => $q->where('department', $posDepartment))
            ->when($posSearch !== '', fn ($q) => $q->where(function ($sub) use ($posSearch) {
                $sub->where('title', 'like', "%{$posSearch}%")
                    ->orWhere('code', 'like', "%{$posSearch}%")
                    ->orWhere('department', 'like', "%{$posSearch}%");
            }))
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->withSum(['requisitions as open_req_openings' => fn ($q) => $q->whereNotIn('status', ['closed'])], 'openings')
            ->orderBy('department')
            ->orderBy('title')
            ->paginate(20, ['*'], 'pos_page')
            ->withQueryString();

        $positions->through(fn ($pos) => [
            'id' => $pos->id,
            'title' => $pos->title,
            'code' => $pos->code,
            'department' => $pos->department,
            'team' => $pos->team,
            'employment_type' => $pos->employment_type,
            'fte' => (float) $pos->fte,
            'headcount_budget' => $pos->headcount_budget,
            'current_headcount' => $pos->employees_count,
            'vacancies' => max(0, $pos->headcount_budget - $pos->employees_count),
            'open_requisition_openings' => (int) ($pos->open_req_openings ?? 0),
            'actionable_vacancies' => max(0, $pos->headcount_budget - $pos->employees_count - (int) ($pos->open_req_openings ?? 0)),
            'is_understaffed' => max(0, $pos->headcount_budget - $pos->employees_count - (int) ($pos->open_req_openings ?? 0)) > 0,
            'is_active' => (bool) $pos->is_active,
            'description' => $pos->description,
            'requirements' => $pos->requirements,
            'summary' => $pos->summary,
            'responsibilities' => $pos->responsibilities,
            'reports_to_position_id' => $pos->reports_to_position_id,
        ]);

        $parentPositions = HrPosition::forTenant($tenantId)
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

        $understaffedCount = app(PositionService::class)->getUnderstaffed($tenantId)->count();

        // --- Departments tab (folds /hr/departments; own filters + paginator) ---
        $deptSearch = trim((string) $request->query('dept_q', ''));
        $deptStatus = $request->query('dept_status');

        $departmentsPane = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->with(['manager:id,name', 'parent:id,name', 'sites:id,name'])
            ->withCount(['employees' => fn ($q) => $q->where('is_active', true)])
            ->when($deptSearch !== '', fn ($q) => $q->where(fn ($i) => $i
                ->where('name', 'like', "%{$deptSearch}%")
                ->orWhere('code', 'like', "%{$deptSearch}%")))
            ->when($deptStatus === 'active', fn ($q) => $q->where('is_active', true))
            ->when($deptStatus === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25, ['*'], 'dept_page')
            ->withQueryString();

        $departmentManagers = User::query()->staff()->orderBy('name')->get(['id', 'name']);
        $departmentParents = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $canDept = $user->canDo('hr.settings.manage') || $user->canDo('hr.employees.manage');

        // --- Org chart tab (folds /hr/orgchart) ---
        $orgHierarchy = app(OrgChartService::class)->getHierarchy($tenantId);
        $orgPeople = HrEmployeeProfile::forTenant($tenantId)
            ->active()
            ->with('user:id,name')
            ->orderBy('position_title')
            ->get()
            ->map(fn ($p) => [
                'user_id' => $p->user_id,
                'name' => $p->user?->name ?? 'Unknown',
                'position_title' => $p->position_title,
                'manager_user_id' => $p->manager_user_id,
            ])
            ->values();
        $canOrgManage = $user->canDo('hr.orgchart.manage') || $user->canDo('hr.employees.manage');

        // --- "Needs attention" triage (drill-down modal from the hero chips) ---
        $triage = $this->buildTriage($tenantId);

        return Inertia::render('hr/employees/index', [
            'profiles' => $profiles,
            'sites' => $sites,
            'departments' => $departments,
            'formData' => $formData,
            'positions' => $positions,
            'parentPositions' => $parentPositions,
            'positionFilters' => [
                'q' => $posSearch,
                'department' => $posDepartment,
                'status' => $posStatus,
            ],
            'departmentsPane' => $departmentsPane,
            'departmentManagers' => $departmentManagers,
            'departmentParents' => $departmentParents,
            'departmentFilters' => [
                'q' => $deptSearch,
                'status' => $deptStatus,
            ],
            'canDept' => $canDept,
            'orgHierarchy' => $orgHierarchy,
            'orgPeople' => $orgPeople,
            'canOrgManage' => $canOrgManage,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'site_id' => $siteId,
                'department' => $department,
                'employment_type' => $employmentType,
                'joined' => $joined,
                'probation' => $probation,
                'sort' => $sortKey,
                'dir' => $sortDir,
            ],
            'summary' => [
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'new_hires' => $newHires,
                'on_probation' => $onProbation,
                'compliance_alerts' => $complianceAlerts,
                'pending_invites' => $pendingInvites,
                'type_counts' => $typeCounts,
                'understaffed_positions' => $understaffedCount,
            ],
            'triage' => $triage,
            'can' => [
                'manage' => $user->canDo('hr.employees.manage'),
                'recruit' => $user->canDo('hr.recruitment.manage'),
            ],
        ]);
    }

    /**
     * Build the three "needs attention" rails surfaced by the hero chips →
     * triage modal: expiring/expired compliance, staff still on probation, and
     * pending login invites. Each list is capped (the rail shows the true total
     * from `summary`, with the list as the actionable head of the queue).
     */
    private function buildTriage(int $tenantId): array
    {
        // Compliance — expired first, then expiring soon, soonest expiry up top.
        $compliance = HrStaffComplianceStatus::query()
            ->whereIn('status', ['expired', 'expiring_soon'])
            ->with(['user:id,name', 'requirement:id,name'])
            ->orderByRaw("FIELD(status, 'expired', 'expiring_soon')")
            ->orderBy('expires_at')
            ->limit(50)
            ->get();

        // Compliance rows are keyed by user — resolve their profile ids so each
        // row can deep-link to the employee profile.
        $profileByUser = HrEmployeeProfile::query()
            ->whereIn('user_id', $compliance->pluck('user_id')->filter()->unique())
            ->pluck('id', 'user_id');

        $probation = HrEmployeeProfile::query()->forTenant($tenantId)
            ->where('is_active', true)
            ->whereNotNull('probation_end_date')
            ->where('probation_end_date', '>=', now())
            ->with('user:id,name')
            ->orderBy('probation_end_date')
            ->limit(50)
            ->get();

        $invites = User::query()->staff()
            ->whereNull('approved_at')
            ->whereNull('last_login_at')
            ->whereHas('hrEmployeeProfile', fn ($p) => $p
                ->where('tenant_id', $tenantId)
                ->where('is_active', true))
            ->with('hrEmployeeProfile:id,user_id,position_title')
            ->orderBy('name')
            ->limit(50)
            ->get();

        return [
            'compliance' => $compliance->map(fn ($s) => [
                'id' => 'comp-'.$s->id,
                'profile_id' => $profileByUser[$s->user_id] ?? null,
                'name' => $s->user?->name ?? 'Unknown',
                'detail' => $s->requirement?->name ?? 'Compliance requirement',
                'status' => $s->status,
                'date' => $s->expires_at?->toDateString(),
            ])->values(),
            'probation' => $probation->map(fn ($p) => [
                'id' => 'prob-'.$p->id,
                'profile_id' => $p->id,
                'name' => $p->user?->name ?? 'Unknown',
                'detail' => $p->position_title ?: 'Employee',
                'status' => 'probation',
                'date' => $p->probation_end_date?->toDateString(),
            ])->values(),
            'invites' => $invites->map(fn ($u) => [
                'id' => 'inv-'.$u->id,
                'profile_id' => $u->hrEmployeeProfile?->id,
                'name' => $u->name,
                'detail' => $u->hrEmployeeProfile?->position_title ?: $u->email,
                'status' => 'pending',
                'date' => null,
            ])->values(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  resendInvite — (re)send a login invite from the triage modal */
    /* ------------------------------------------------------------------ */

    public function resendInvite(Request $request, HrEmployeeProfile $profile)
    {
        abort_unless($request->user()?->canDo('hr.employees.manage'), 403);

        $account = $profile->user;
        $this->assertHrTenantAccess(
            $this->resolveHrTenantIdForUser($request->user()),
            $profile->tenant_id,
        );

        if (! $account) {
            return back()->with('error', 'This employee has no login account to invite.');
        }

        if ($account->approved_at !== null) {
            return back()->withErrors([
                'invite' => 'This employee already has an active login and does not need another invitation.',
            ]);
        }

        $token = Password::broker()->createToken($account);
        $account->notify(new EmployeeInviteNotification($token, $profile));

        return back()->with('success', "Login invite sent to {$account->name}.");
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create a new employee (User + profile + role) */
    /* ------------------------------------------------------------------ */

    public function store(StoreEmployeeRequest $request, EmployeeIntakeService $intake)
    {
        $actor = $request->user();
        $data = $request->validated();
        $tenantId = $this->resolveHrTenantIdForUser($actor);
        $data['team'] = HrEmployeeProfile::canonicalTeamForTenant($data['team'] ?? null, $tenantId);
        $roleName = $data['role'] ?? 'support_worker';

        $positionTitle = $data['position_title'] ?? null;
        if (empty($positionTitle) && ! empty($data['position_id'])) {
            $positionTitle = HrPosition::find($data['position_id'])?->title;
        }

        // Dedupe gate: if this email already belongs to a staff member who has a
        // profile, require explicit confirmation before linking/overwriting it
        // (the modal's "Link to existing record" callout). A user without a
        // profile (e.g. a candidate-created account) links silently.
        $existingUser = User::where('email', $data['email'])->first();
        if ($existingUser?->hrEmployeeProfile && ! $request->boolean('link_existing')) {
            return back()
                ->withInput()
                ->withErrors([
                    'email' => 'A staff member already uses this email. Enable “Link to existing record” to update their profile.',
                ]);
        }

        try {
            $profile = $intake->intake(
                name: $data['name'],
                email: $data['email'],
                roleName: $roleName,
                profileAttributes: [
                    'preferred_name' => $data['preferred_name'] ?? null,
                    'position_id' => $data['position_id'] ?? null,
                    'position_title' => $positionTitle ?: 'New starter',
                    'position_role' => $roleName,
                    'employment_type' => $data['employment_type'] ?? 'full_time',
                    'department' => $data['department'] ?? null,
                    'team' => $data['team'],
                    'primary_site_id' => $data['primary_site_id'] ?? null,
                    'manager_user_id' => $data['manager_user_id'] ?? null,
                    'start_date' => $data['start_date'] ?? now()->toDateString(),
                    'work_phone' => $data['work_phone'] ?? null,
                    'work_rights_status' => $data['work_rights_status'] ?? null,
                    'visa_type' => $data['visa_type'] ?? null,
                    'visa_expires_at' => $data['visa_expires_at'] ?? null,
                    'emergency_contacts' => $data['emergency_contacts'] ?? null,
                ],
                actorId: $actor->id,
                tenantId: $tenantId,
                startOnboarding: $request->boolean('start_onboarding', true),
                sendInvite: $request->boolean('send_invite', false),
                source: 'manual',
            );
        } catch (\InvalidArgumentException $e) {
            // D-2 role-assignment guard (admin-grade / external personas).
            return back()->withInput()->withErrors(['role' => $e->getMessage()]);
        }

        return redirect()
            ->route('hr.people.show', $profile->id)
            ->with('success', "{$data['name']} has been added to your team.");
    }

    /* ------------------------------------------------------------------ */
    /*  setActive — deactivate / reactivate a single employee (row menu) */
    /* ------------------------------------------------------------------ */

    public function setActive(Request $request, HrEmployeeProfile $profile)
    {
        abort_unless($request->user()?->canDo('hr.employees.manage'), 403);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $profile->update(['is_active' => $data['is_active']]);

        // Re-hiring a leaver whose login was revoked on offboarding completion
        // must restore their access, or the "reactivated" employee can never
        // sign in again (approval is what gates login).
        if ($data['is_active'] && $profile->user && is_null($profile->user->approved_at)) {
            $profile->user->forceFill(['approved_at' => now()])->save();

            // D-3: lightweight reactivation restores login approval directly,
            // so record the User write just like the full re-hire workflow.
            AuditLogger::log('user.login_reactivated', $profile->user, [
                'actor_id' => $request->user()->id,
                'employee_profile_id' => $profile->id,
                'reason' => 'employee_profile_reactivated',
            ]);
        }

        return back()->with(
            'success',
            $data['is_active']
                ? "{$profile->user?->name} has been reactivated."
                : "{$profile->user?->name} has been deactivated.",
        );
    }

    /* ------------------------------------------------------------------ */
    /*  rehire — full welcome-back workflow for a former employee */
    /* ------------------------------------------------------------------ */

    public function rehire(Request $request, HrEmployeeProfile $profile, EmployeeIntakeService $intake)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $profile->tenant_id);

        if ($profile->is_active) {
            return back()->with('error', "{$profile->user?->name} is already active — nothing to re-hire.");
        }

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'position_title' => ['nullable', 'string', 'max:255'],
            'position_role' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', 'string', 'in:full_time,part_time,casual,fixed_term,contractor,permanent'],
            'primary_site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:168'],
            'send_invite' => ['nullable', 'boolean'],
            'start_onboarding' => ['nullable', 'boolean'],
        ]);

        $attributes = collect($data)
            ->only(['start_date', 'position_title', 'position_role', 'employment_type', 'primary_site_id', 'hours_per_week'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();

        try {
            $profile = $intake->rehire(
                $profile,
                $attributes,
                $user->id,
                sendInvite: $request->boolean('send_invite', true),
                startOnboarding: $request->boolean('start_onboarding', true),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$profile->user?->name} has been re-hired — welcome back!");
    }

    /* ------------------------------------------------------------------ */
    /*  bulkAction — multi-select bulk operations from the People table */
    /* ------------------------------------------------------------------ */

    public function bulkAction(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.employees.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:deactivate,reactivate,assign_site,assign_department,assign_manager'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'site_id' => ['required_if:action,assign_site', 'nullable', 'integer', 'exists:sites,id'],
            'department_id' => ['required_if:action,assign_department', 'nullable', 'integer', 'exists:hr_departments,id'],
            'manager_user_id' => ['required_if:action,assign_manager', 'nullable', 'integer', 'exists:users,id'],
        ]);

        if ($data['action'] === 'assign_department') {
            // keep the denormalised label column in sync with the FK so the
            // table + filter stay consistent (see departments brief).
            $department = HrDepartment::where('tenant_id', $tenantId)->find($data['department_id']);
            abort_unless($department !== null, 422, 'That department does not belong to this organisation.');
        }

        $attributes = match ($data['action']) {
            'deactivate' => ['is_active' => false],
            'reactivate' => ['is_active' => true],
            'assign_site' => ['primary_site_id' => $data['site_id']],
            'assign_department' => [
                'department_id' => $data['department_id'],
                'department' => $department->name,
            ],
            'assign_manager' => ['manager_user_id' => $data['manager_user_id']],
        };

        // Update model-by-model (not a mass query update): bulk query updates
        // skip Eloquent events, so AuditableChanges would never log the rows
        // and the change would be invisible in /hr/settings/audit-log.
        $profiles = HrEmployeeProfile::where('tenant_id', $tenantId)
            ->whereIn('id', $data['ids'])
            ->get();

        foreach ($profiles as $profile) {
            $profile->update($attributes);
        }

        $count = $profiles->count();

        return back()->with('success', "{$count} ".($count === 1 ? 'person' : 'people').' updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — tabbed profile with related data */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

        $profile->load([
            'user:id,name,email',
            'primarySite:id,name',
            'departmentRelation:id,name',
            'documents',
            'offer:id,application_id,position_title,proposed_start_date,employment_type',
        ]);

        $userId = $profile->user_id;
        $canViewInjuries = $user->canDo('hazards.view');

        // H&S owns workplace injuries and RTW plans. HR federates a minimal,
        // read-only employee summary only for viewers with the existing H&S
        // read permission; there is deliberately no HR mutation path.
        $workplaceInjuries = $canViewInjuries
            ? WorkplaceInjury::query()
                ->forWorker($userId)
                ->with([
                    'site:id,name',
                    'returnToWorkPlans' => fn ($query) => $query->orderByDesc('created_at'),
                ])
                ->orderByDesc('injury_date')
                ->get()
                ->map(function (WorkplaceInjury $injury) {
                    $latestPlan = $injury->returnToWorkPlans->first();

                    return [
                        'id' => $injury->id,
                        'reference' => $injury->reference_number ?: 'Injury #'.$injury->id,
                        'injury_date' => $injury->injury_date?->toDateString(),
                        'injury_type' => $injury->injury_type,
                        'body_part_affected' => $injury->body_part_affected,
                        'severity' => $injury->severity,
                        'status' => $injury->status,
                        'lost_time_days' => (int) $injury->lost_time_days,
                        'expected_return_date' => $injury->expected_return_date?->toDateString(),
                        'actual_return_date' => $injury->actual_return_date?->toDateString(),
                        'site' => $injury->site?->name,
                        'return_to_work' => $latestPlan ? [
                            'status' => $latestPlan->status,
                            'plan_start_date' => $latestPlan->plan_start_date?->toDateString(),
                            'plan_end_date' => $latestPlan->plan_end_date?->toDateString(),
                            'next_review_date' => $latestPlan->next_review_date?->toDateString(),
                        ] : null,
                        'url' => route('health-safety.injuries.show', $injury),
                    ];
                })
                ->values()
            : collect();

        // Tenure
        $tenure = null;
        if ($profile->start_date) {
            $months = (int) $profile->start_date->diffInMonths(now());
            $tenure = ['years' => intdiv($months, 12), 'months' => $months % 12];
        }

        // Manager
        $manager = null;
        if ($profile->manager_user_id) {
            $mp = HrEmployeeProfile::where('user_id', $profile->manager_user_id)->with('user:id,name')->first();
            if ($mp) {
                $manager = ['id' => $mp->id, 'name' => $mp->user?->name ?? 'Unknown', 'position_title' => $mp->position_title, 'profile_photo_path' => $mp->profile_photo_path];
            }
        }

        // Direct reports
        $directReports = HrEmployeeProfile::where('manager_user_id', $userId)
            ->where('is_active', true)->with('user:id,name')->limit(20)->get()
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->user?->name ?? 'Unknown', 'position_title' => $r->position_title]);

        // Compliance
        $rawStatuses = HrStaffComplianceStatus::where('user_id', $userId)
            ->with('requirement:id,code,name,category,check_type,hard_stop')->get();

        $complianceStatuses = $rawStatuses->map(fn ($s) => [
            'id' => $s->id, 'requirement_name' => $s->requirement?->name ?? '', 'requirement_type' => $s->requirement?->check_type ?? '',
            'status' => $s->status, 'expiry_date' => $s->expires_at?->toDateString(), 'completed_date' => $s->valid_from?->toDateString(),
        ])->values();

        $complianceSummary = [
            'compliant' => $rawStatuses->where('status', 'compliant')->count(),
            'expiring_soon' => $rawStatuses->where('status', 'expiring_soon')->count(),
            'expired' => $rawStatuses->where('status', 'expired')->count(),
            'not_started' => $rawStatuses->where('status', 'not_started')->count(),
            'total' => $rawStatuses->count(),
        ];

        // Leave
        $leaveBalances = HrLeaveBalance::where('user_id', $userId)->where('year', now()->year)->get()
            ->map(fn ($lb) => [
                'id' => $lb->id, 'leave_type' => $lb->leave_type,
                'accrued_hours' => (float) $lb->accrued_hours, 'used_hours' => (float) $lb->used_hours,
                'balance_hours' => (float) $lb->balance_hours,
                'as_at_date' => $lb->last_synced_at?->toDateString() ?? now()->toDateString(),
            ]);

        $recentLeaveRequests = HrLeaveRequest::where('user_id', $userId)
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn ($lr) => [
                'id' => $lr->id, 'leave_type' => $lr->leave_type, 'status' => $lr->status,
                'starts_at' => $lr->starts_at?->toDateString(), 'ends_at' => $lr->ends_at?->toDateString(),
                'hours_requested' => (float) ($lr->hours_requested ?? 0),
            ]);

        // Onboarding
        $onboardingChecklists = HrOnboardingChecklist::where('employee_profile_id', $profile->id)
            ->with(['tasks' => fn ($q) => $q->orderBy('category')->orderBy('sort_order')])
            ->orderByDesc('created_at')->get()
            ->map(fn ($cl) => [
                'id' => $cl->id, 'name' => $cl->template_key ?? 'Onboarding Checklist',
                'status' => $cl->status, 'due_date' => $cl->due_date?->toDateString(),
                'started_at' => $cl->started_at?->toDateString(), 'completed_at' => $cl->completed_at?->toDateString(),
                'tasks' => $cl->tasks->map(fn ($t) => [
                    'id' => $t->id, 'category' => $t->category, 'title' => $t->title,
                    'description' => $t->description, 'is_required' => (bool) $t->is_required,
                    'status' => $t->status, 'assigned_to_role' => $t->assigned_to_role,
                    'sign_off_required' => (bool) $t->sign_off_required,
                    'completed_at' => $t->completed_at?->toDateString(),
                ])->values(),
            ]);

        // Performance reviews (restricted)
        $performanceReviews = $user->canDo('hr.employees.viewRestricted')
            ? HrPerformanceReview::where('employee_user_id', $userId)
                ->with('reviewer:id,name')->orderByDesc('review_period_end')->limit(10)->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'review_type' => $r->review_type, 'status' => $r->status,
                    'overall_rating' => $r->overall_rating,
                    'period_start' => $r->review_period_start?->toDateString(),
                    'period_end' => $r->review_period_end?->toDateString(),
                    'reviewer_name' => $r->reviewer?->name, 'next_review_date' => $r->next_review_date?->toDateString(),
                    'employee_signed_off' => (bool) $r->employee_signed_off,
                    'manager_signed_off' => (bool) $r->manager_signed_off,
                ])
            : collect();

        // Probation reviews
        $probationReviews = HrProbationReview::where('employee_user_id', $userId)
            ->with('reviewer:id,name')->orderBy('review_number')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'review_number' => $r->review_number, 'review_date' => $r->review_date?->toDateString(),
                'status' => $r->status, 'recommendation' => $r->recommendation,
                'reviewer_name' => $r->reviewer?->name, 'extension_weeks' => $r->extension_weeks,
            ]);

        // PIPs
        $pips = HrPerformanceImprovementPlan::where('employee_user_id', $userId)
            ->with('milestones')->orderByDesc('start_date')->limit(5)->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'status' => $p->status, 'reason' => $p->reason,
                'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(),
                'outcome' => $p->outcome,
                'milestones' => $p->milestones->map(fn ($m) => [
                    'id' => $m->id, 'title' => $m->title, 'due_date' => $m->due_date?->toDateString(),
                    'status' => $m->status, 'outcome' => $m->outcome,
                ])->values(),
            ]);

        // Development goals
        $developmentGoals = HrDevelopmentGoal::where('employee_user_id', $userId)
            ->orderByDesc('created_at')->limit(10)->get()
            ->map(fn ($g) => [
                'id' => $g->id, 'title' => $g->title, 'status' => $g->status,
                'progress_percent' => $g->progress_percent ?? 0,
                'due_date' => $g->due_date?->toDateString(),
                'category' => $g->category,
                'competency_area' => $g->competency_area,
            ]);

        // Performance summary
        $activeGoals = HrDevelopmentGoal::where('employee_user_id', $userId)
            ->whereIn('status', ['not_started', 'in_progress', 'blocked']);
        $performanceSummary = [
            'latest_rating' => $performanceReviews->first()['overall_rating'] ?? null,
            'next_review_date' => $performanceReviews->pluck('next_review_date')->filter()->sort()->first(),
            'active_goals_count' => (clone $activeGoals)->count(),
            'active_goals_avg' => (int) round((clone $activeGoals)->avg('progress_percent') ?? 0),
            'has_active_pip' => $pips->whereIn('status', ['active', 'in_progress'])->isNotEmpty(),
        ];

        // Training
        $courseEnrollments = HrCourseEnrollment::where('user_id', $userId)
            ->with('course:id,title,category,duration_hours')->orderByDesc('enrolled_at')->limit(20)->get()
            ->map(fn ($e) => [
                'id' => $e->id, 'course_name' => $e->course?->title, 'category' => $e->course?->category,
                'status' => $e->status, 'enrolled_at' => $e->enrolled_at?->toDateString(),
                'completed_at' => $e->completed_at?->toDateString(), 'score' => $e->score,
            ]);

        // Skills
        $employeeSkills = HrEmployeeSkill::where('employee_profile_id', $profile->id)
            ->with('skill:id,name,category')->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'skill_name' => $s->skill?->name, 'category' => $s->skill?->category,
                'proficiency_level' => $s->proficiency_level, 'self_assessed' => (bool) $s->self_assessed,
            ]);

        // Competency assessments
        $competencyAssessments = HrCompetencyAssessment::where('employee_profile_id', $profile->id)
            ->with('competency:id,name,category')->orderByDesc('assessment_date')->limit(20)->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'competency_name' => $a->competency?->name, 'category' => $a->competency?->category,
                'proficiency_level' => $a->assessed_level, 'target_level' => $a->target_level,
                'assessment_date' => $a->assessment_date?->toDateString(),
            ]);

        // Driver eligibility
        $driverData = null;
        try {
            $driverEligibility = HrDriverEligibility::where('user_id', $userId)->first();
            if ($driverEligibility) {
                $driverData = [
                    'id' => $driverEligibility->id, 'status' => $driverEligibility->status,
                    'licence_number' => $driverEligibility->licence_number, 'licence_class' => $driverEligibility->licence_class,
                    'licence_endorsements' => $driverEligibility->licence_endorsements,
                    'licence_expires_at' => $driverEligibility->licence_expires_at?->toDateString(),
                    'can_drive_clients' => (bool) $driverEligibility->can_drive_clients,
                    'incident_free_since' => $driverEligibility->incident_free_since?->toDateString(),
                    'next_review_at' => $driverEligibility->next_review_at?->toDateString(),
                ];
            }
        } catch (\Exception $e) {
            // Table may not exist yet
        }

        // Vetting / background checks
        $backgroundChecks = StaffBackgroundCheck::where('user_id', $userId)
            ->orderByDesc('check_date')->get()
            ->map(fn ($c) => [
                'id' => $c->id, 'check_type' => $c->check_type, 'status' => $c->status,
                'provider' => $c->provider, 'reference_number' => $c->reference_number,
                'check_date' => $c->check_date?->toDateString(), 'expires_at' => $c->expires_at?->toDateString(),
                'risk_decision' => $c->risk_decision,
            ]);

        // Supervision notes (restricted)
        $supervisionNotes = $user->canDo('hr.employees.viewRestricted')
            ? HrSupervisionNote::where('employee_user_id', $userId)
                ->with('supervisor:id,name')->orderByDesc('session_date')->limit(10)->get()
                ->map(fn ($n) => [
                    'id' => $n->id, 'session_date' => $n->session_date?->toDateString(), 'session_type' => $n->session_type,
                    'duration_minutes' => $n->duration_minutes, 'supervisor_name' => $n->supervisor?->name,
                    'topics_discussed' => $n->topics_discussed, 'actions_agreed' => $n->actions_agreed,
                    'next_session_date' => $n->next_session_date?->toDateString(),
                ])
            : collect();

        // Cases (restricted — disciplinary / HR cases)
        $cases = $user->canDo('hr.employees.viewRestricted')
            ? HrCase::where('user_id', $userId)
                ->with('assignedTo:id,name')->orderByDesc('opened_at')->limit(10)->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'case_number' => $c->case_number, 'case_type' => $c->case_type,
                    'severity' => $c->severity, 'status' => $c->status, 'title' => $c->title,
                    'opened_at' => $c->opened_at?->toDateString(), 'closed_at' => $c->closed_at?->toDateString(),
                    'assigned_to_name' => $c->assignedTo?->name,
                ])
            : collect();

        // Asset assignments
        $assetAssignments = HrAssetAssignment::where('employee_profile_id', $profile->id)
            ->with('asset:id,asset_tag,name,category,serial_number')->orderByDesc('assigned_at')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'asset_name' => $a->asset?->name, 'asset_tag' => $a->asset?->asset_tag,
                'category' => $a->asset?->category, 'serial_number' => $a->asset?->serial_number,
                'assigned_at' => $a->assigned_at?->toDateString(), 'returned_at' => $a->returned_at?->toDateString(),
                'condition' => $a->condition_on_assign,
            ]);

        // Policy attestations
        $policyAttestations = HrPolicyAttestation::where('user_id', $userId)
            ->with('policy:id,title')->orderByDesc('attested_at')->limit(20)->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'policy_name' => $a->policy?->title,
                'attested_at' => $a->attested_at?->toDateString(),
            ]);

        return Inertia::render('hr/employees/show', [
            'profile' => [
                'id' => $profile->id,
                'employee_number' => $profile->employee_number,
                'position_title' => $profile->position_title,
                'position_role' => $profile->position_role,
                'employment_type' => $profile->employment_type,
                'contract_type' => $profile->contract_type,
                'department' => $profile->departmentRelation?->name ?? $profile->department,
                'team' => $profile->team,
                'is_active' => (bool) $profile->is_active,
                'start_date' => $profile->start_date?->toDateString(),
                'end_date' => $profile->end_date?->toDateString(),
                'probation_end_date' => $profile->probation_end_date?->toDateString(),
                'hours_per_week' => $profile->hours_per_week,
                'employment_history' => $profile->employment_history ?? [],
                'pay_rate' => $user->canDo('hr.employees.viewFinancial') ? $profile->hourly_rate : null,
                'pay_frequency' => $user->canDo('hr.employees.viewFinancial') ? $profile->pay_frequency : null,
                'bio' => $profile->bio,
                'preferred_name' => $profile->preferred_name,
                'profile_photo_path' => $profile->profile_photo_path,
                'is_first_aider' => (bool) $profile->is_first_aider,
                'is_fire_warden' => (bool) $profile->is_fire_warden,
                'can_drive_clients' => (bool) $profile->can_drive_clients,
                'work_rights_status' => $profile->work_rights_status,
                'visa_type' => $profile->visa_type,
                'visa_expires_at' => $profile->visa_expires_at?->toDateString(),
                'notes' => $profile->notes,
                'emergency_contact_name' => $profile->emergency_contact_name ?? ($profile->emergency_contacts[0]['name'] ?? null),
                'emergency_contact_phone' => $profile->emergency_contact_phone ?? ($profile->emergency_contacts[0]['phone'] ?? null),
                'emergency_contact_relationship' => $profile->emergency_contact_relationship ?? ($profile->emergency_contacts[0]['relationship'] ?? null),
                'user' => ['id' => $profile->user->id, 'name' => $profile->user->name, 'email' => $profile->user->email],
                'primary_site' => $profile->primarySite ? ['id' => $profile->primarySite->id, 'name' => $profile->primarySite->name] : null,
                'documents' => $profile->documents->map(fn ($d) => [
                    'id' => $d->id, 'title' => $d->title, 'category' => $d->category,
                    'original_name' => $d->original_name, 'created_at' => $d->created_at?->toDateString(),
                    'expires_at' => $d->expires_at?->toDateString(),
                    'signed_by_employee' => (bool) $d->signed_by_employee,
                ])->values(),
            ],
            'tenure' => $tenure,
            'manager' => $manager,
            'directReports' => $directReports,
            'complianceStatuses' => $complianceStatuses,
            'complianceSummary' => $complianceSummary,
            'leaveBalances' => $leaveBalances,
            'recentLeaveRequests' => $recentLeaveRequests,
            'onboardingChecklists' => $onboardingChecklists,
            'performanceReviews' => $performanceReviews,
            'probationReviews' => $probationReviews,
            'pips' => $pips,
            'developmentGoals' => $developmentGoals,
            'performanceSummary' => $performanceSummary,
            'courseEnrollments' => $courseEnrollments,
            'employeeSkills' => $employeeSkills,
            'competencyAssessments' => $competencyAssessments,
            'driverEligibility' => $driverData,
            'backgroundChecks' => $backgroundChecks,
            'supervisionNotes' => $supervisionNotes,
            'cases' => $cases,
            'assetAssignments' => $assetAssignments,
            'policyAttestations' => $policyAttestations,
            'safeWorkProcedures' => $this->employeeProcedures($user, $profile),
            ...($canViewInjuries ? ['workplaceInjuries' => $workplaceInjuries] : []),
            // Re-hire wizard site options — only needed when the viewer can
            // manage AND the profile is a former employee.
            'rehireSites' => $user->canDo('hr.employees.manage') && ! $profile->is_active
                ? Site::orderBy('name')->get(['id', 'name'])
                : [],
            'can' => [
                'manage' => $user->canDo('hr.employees.manage'),
                'viewSensitive' => $user->canDo('hr.employees.viewRestricted'),
                'viewInjuries' => $canViewInjuries,
            ],
        ]);
    }

    /**
     * Safe Work Procedures applicable to this employee's role(s), with the employee's
     * own acknowledgement status (read-only compliance view for the manager).
     */
    private function employeeProcedures($viewer, HrEmployeeProfile $profile): Collection
    {
        if (! $viewer?->canDo('procedures.view')) {
            return collect();
        }

        $roleKeys = $profile->user?->roles()->pluck('name')->all() ?? [];
        $acked = ProcedureAcknowledgement::query()
            ->where('user_id', $profile->user_id)
            ->pluck('version_acknowledged', 'safe_work_procedure_id');

        return SafeWorkProcedure::query()->applicableToRoles($roleKeys)
            ->orderBy('title')
            ->limit(25)
            ->get(['id', 'reference_number', 'title', 'category', 'status', 'review_date', 'current_version'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'reference_number' => $p->reference_number,
                'title' => $p->title,
                'category' => $p->category,
                'status' => $p->status,
                'review_date' => $p->review_date?->toDateString(),
                'acknowledged' => (int) ($acked[$p->id] ?? 0) === (int) $p->current_version,
            ])->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Edit */
    /* ------------------------------------------------------------------ */

    public function edit(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);
        $this->assertHrTenantAccess($this->resolveHrTenantIdForUser($user), $profile->tenant_id);

        $profile->load('user:id,name,email');

        $profilePayload = $profile->toArray();
        foreach (['start_date', 'end_date', 'probation_end_date', 'visa_expires_at'] as $dateField) {
            $profilePayload[$dateField] = $profile->{$dateField}?->toDateString();
        }

        $sites = Site::orderBy('name')
            ->get(['id', 'name']);

        $departments = HrDepartment::query()
            ->where(fn ($q) => $q->where('tenant_id', $user->tenant_id)->orWhereNull('tenant_id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // The edit page renders these as {value, label} select options.
        $options = fn (array $values) => array_map(fn (string $value) => [
            'value' => $value,
            'label' => ucwords(str_replace('_', ' ', $value)),
        ], $values);

        return Inertia::render('hr/employees/edit', [
            'profile' => $profilePayload,
            'sites' => $sites,
            'departments' => $departments,
            'employmentTypes' => $options(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor']),
            'contractTypes' => $options(['permanent', 'fixed_term', 'casual', 'contractor']),
            'payFrequencies' => $options(['weekly', 'fortnightly', 'monthly']),
            'workRightsStatuses' => $options(['citizen', 'permanent_resident', 'resident_visa', 'work_visa', 'student_visa', 'other']),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update */
    /* ------------------------------------------------------------------ */

    public function update(
        UpdateEmployeeProfileRequest $request,
        HrEmployeeProfile $profile,
        ItProvisioningWorkflowService $provisioningWorkflows,
    ) {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $profile->tenant_id);

        $validated = $request->validated();
        if (array_key_exists('team', $validated)) {
            $validated['team'] = HrEmployeeProfile::canonicalTeamForTenant($validated['team'], $tenantId);
        }
        $validated['updated_by'] = $user->id;

        DB::transaction(function () use ($profile, $validated, $user, $provisioningWorkflows): void {
            $tracked = ['position_role', 'primary_site_id', 'employment_type'];
            $before = collect($tracked)->mapWithKeys(fn (string $field) => [$field => $profile->{$field}])->all();
            $profile->update($validated);
            $profile->refresh();

            $changes = [];
            foreach ($tracked as $field) {
                if (! array_key_exists($field, $validated) || $before[$field] == $profile->{$field}) {
                    continue;
                }
                $changes[$field] = ['from' => $before[$field], 'to' => $profile->{$field}];
            }

            if ($changes !== [] && Schema::hasTable('it_provisioning_templates')) {
                $digest = substr(hash('sha256', json_encode($changes, JSON_THROW_ON_ERROR)), 0, 20);
                $stamp = $profile->updated_at?->format('YmdHis.u') ?? now()->format('YmdHis.u');
                $provisioningWorkflows->tryLaunchMover(
                    profile: $profile,
                    changes: $changes,
                    sourceEventKey: "hr-profile:{$profile->id}:{$stamp}:{$digest}",
                    actorId: $user->id,
                    effectiveAt: now(),
                );
            }
        });

        return redirect()->back()->with('success', 'Employee profile updated successfully.');
    }
}
