<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
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
use App\Domain\Hr\Models\HrOffer;
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
use App\Domain\Hr\Services\HrEquipmentAccessProjectionService;
use App\Domain\Hr\Services\OrgChartService;
use App\Domain\Hr\Services\PeopleMutationLockService;
use App\Domain\It\Services\ItProvisioningWorkflowService;
use App\Http\Controllers\Controller;
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
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmployeeProfileController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Index — paginated employee list */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($user);

        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status'); // 'active', 'inactive', or null for all
        $siteId = $request->query('site_id');
        $requestedSiteId = is_numeric($siteId) && (int) $siteId > 0 ? (int) $siteId : null;
        $hasSiteFilter = $siteId !== null && $siteId !== '';
        $canFilterSite = $requestedSiteId !== null
            && in_array($requestedSiteId, $accessibleSiteIds, true);
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
        $siteSortExpression = $accessibleSiteIds === []
            ? 'NULL'
            : sprintf(
                'CASE WHEN hr_employee_profiles.primary_site_id IN (%s) THEN sites.name ELSE NULL END',
                implode(', ', array_fill(0, count($accessibleSiteIds), '?')),
            );

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
            ->with(['hrEmployeeProfile' => fn ($profileQuery) => $profileQuery
                ->withTrashed()
                ->with('primarySite:id,name')]);
        $siteAccess->applyHistoricalStaffSiteScope($profiles, $user);
        $profiles = $profiles
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%");
            })
            )
            ->when($status === 'active', fn ($q) => $q->where(function ($statusQuery) {
                $statusQuery->whereHas('hrEmployeeProfile', fn ($profile) => $profile
                    ->withTrashed()
                    ->whereNull('deleted_at')
                    ->where('is_active', true));
            })
            )
            ->when($status === 'inactive', fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($profile) => $profile
                ->withTrashed()
                ->where(fn ($inactive) => $inactive
                    ->where('is_active', false)
                    ->orWhereNotNull('deleted_at')))
            )
            ->when($hasSiteFilter, function ($q) use ($canFilterSite, $requestedSiteId) {
                if (! $canFilterSite) {
                    return $q->whereRaw('1 = 0');
                }

                return $q->whereHas('hrEmployeeProfile', function ($profileQuery) use ($requestedSiteId) {
                    $profileQuery->withTrashed()->where(function ($siteQuery) use ($requestedSiteId) {
                        $siteQuery
                            ->where('primary_site_id', $requestedSiteId)
                            ->orWhereJsonContains('secondary_site_ids', $requestedSiteId);
                    });
                });
            })
            ->when($department, fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($p) => $p
                ->withTrashed()
                ->where('department_id', (int) $department))
            )
            ->when($employmentType, fn ($q) => $q->whereHas('hrEmployeeProfile', fn ($p) => $p
                ->withTrashed()
                ->where('employment_type', $employmentType))
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
            ->when(
                $sortKey === 'site',
                fn ($q) => $q->orderByRaw("{$siteSortExpression} {$sortDir}", $accessibleSiteIds),
                fn ($q) => $q->orderBy($sortColumn, $sortDir),
            )
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate(20)
            ->through(function (User $staffUser) use ($accessibleSiteIds) {
                $profile = $staffUser->hrEmployeeProfile;
                $isActive = $profile !== null
                    && ! $profile->trashed()
                    && (bool) $profile->is_active;
                $primarySite = $profile
                    && $profile->primarySite
                    && in_array((int) $profile->primary_site_id, $accessibleSiteIds, true)
                        ? $profile->primarySite
                        : null;

                return [
                    'id' => $staffUser->id,
                    'profile_id' => $profile?->id,
                    'employee_number' => $profile?->employee_number,
                    'position_title' => $profile?->position_title,
                    'employment_type' => $profile?->employment_type,
                    'department' => $profile?->department,
                    'is_active' => $isActive,
                    'start_date' => $profile?->start_date?->toDateString(),
                    // Re-hire wizard prefill — only meaningful (and only sent)
                    // for former employees.
                    'end_date' => $profile && ! $isActive ? $profile->end_date?->toDateString() : null,
                    'position_role' => $profile && ! $isActive ? $profile->position_role : null,
                    'hours_per_week' => $profile && ! $isActive && $profile->hours_per_week !== null
                        ? (float) $profile->hours_per_week
                        : null,
                    'employment_history' => $profile && ! $isActive
                        ? $this->shapedEmploymentHistory($profile->employment_history)
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

        $sitesQuery = Site::query()->orderBy('name');
        $siteAccess->applySiteScope($sitesQuery, $user);
        $sites = $sitesQuery->get(['id', 'name']);

        // Summary stats for mini dashboard
        $activeCount = $this->visibleProfilesQuery($user, $siteAccess)
            ->where('is_active', true)
            ->count();
        $inactiveCount = $this->visibleProfilesQuery($user, $siteAccess, includeArchived: true)
            ->where(fn ($inactive) => $inactive
                ->where('is_active', false)
                ->orWhereNotNull('deleted_at'))
            ->count();
        $newHires = $this->visibleProfilesQuery($user, $siteAccess)
            ->where('is_active', true)
            ->where('start_date', '>=', now()->subDays(30))
            ->count();
        $onProbation = $this->visibleProfilesQuery($user, $siteAccess)
            ->where('is_active', true)
            ->whereNotNull('probation_end_date')
            ->where('probation_end_date', '>=', now())
            ->count();
        $complianceAlerts = HrStaffComplianceStatus::query()
            ->whereIn('user_id', $this->visibleStaffQuery($user, $siteAccess)->select('users.id'))
            ->whereIn('status', ['expired', 'expiring_soon'])
            ->count();
        // Pending invites — active employee profiles whose login is not active yet.
        $pendingInvitesQuery = User::query()->staff()
            ->whereNull('approved_at')
            ->whereNull('last_login_at')
            ->whereHas('hrEmployeeProfile', fn ($p) => $p
                ->where('is_active', true));
        $siteAccess->applyHistoricalStaffSiteScope($pendingInvitesQuery, $user);
        $pendingInvites = $pendingInvitesQuery->count();

        // Employment type breakdown
        $typeCounts = $this->visibleProfilesQuery($user, $siteAccess)
            ->where('is_active', true)
            ->selectRaw('employment_type, count(*) as count')
            ->groupBy('employment_type')
            ->pluck('count', 'employment_type')
            ->toArray();

        // Departments list for filter (from managed departments table)
        $departments = HrDepartment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Option data for the Add-Employee wizard (manager-only surface).
        $canManage = $user->canDo('hr.employees.manage');
        $formData = $canManage ? [
            'positions' => HrPosition::query()
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])
                ->values(),
            'managers' => $this->currentVisibleStaffQuery($user, $siteAccess)
                ->orderBy('name')
                ->get(['id', 'name'])
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
        // Positions and departments are application-global configuration. Their
        // people-derived counts are still limited to the viewer's approved Sites.
        $posSearch = trim((string) $request->query('pq', ''));
        $posDepartment = $request->query('pdepartment');
        $posStatus = $request->query('pstatus');

        $positions = HrPosition::query()
            ->when($posStatus === 'active', fn ($q) => $q->where('is_active', true))
            ->when($posStatus === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($posDepartment, fn ($q) => $q->where('department', $posDepartment))
            ->when($posSearch !== '', fn ($q) => $q->where(function ($sub) use ($posSearch) {
                $sub->where('title', 'like', "%{$posSearch}%")
                    ->orWhere('code', 'like', "%{$posSearch}%")
                    ->orWhere('department', 'like', "%{$posSearch}%");
            }))
            ->withCount(['employees' => fn ($q) => $q
                ->where('is_active', true)
                ->whereIn('user_id', $this->visibleStaffQuery($user, $siteAccess)->select('users.id'))])
            ->withSum(['requisitions as open_req_openings' => fn ($q) => $q
                ->whereNotIn('status', ['closed'])
                ->whereIn('site_id', $accessibleSiteIds)], 'openings')
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

        $parentPositions = HrPosition::query()
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

        $understaffedQuery = HrPosition::query()
            ->select(['hr_positions.id', 'hr_positions.headcount_budget'])
            ->active()
            ->withCount(['employees' => fn ($q) => $q
                ->where('is_active', true)
                ->whereIn('user_id', $this->visibleStaffQuery($user, $siteAccess)->select('users.id'))])
            ->withSum(['requisitions as open_req_openings' => fn ($q) => $q
                ->whereNotIn('status', ['closed'])
                ->whereIn('site_id', $accessibleSiteIds)], 'openings');
        $understaffedCount = DB::query()
            ->fromSub($understaffedQuery, 'understaffed_positions')
            ->whereRaw('headcount_budget - employees_count - COALESCE(open_req_openings, 0) > 0')
            ->count();

        // --- Departments tab (folds /hr/departments; own filters + paginator) ---
        $deptSearch = trim((string) $request->query('dept_q', ''));
        $deptStatus = $request->query('dept_status');

        $departmentsPane = HrDepartment::query()
            ->with([
                'manager' => fn ($query) => $query
                    ->whereIn('users.id', $this->currentVisibleStaffQuery($user, $siteAccess)->select('users.id'))
                    ->select(['users.id', 'users.name']),
                'parent:id,name',
                'sites' => fn ($query) => $query
                    ->whereIn('sites.id', $accessibleSiteIds)
                    ->select(['sites.id', 'sites.name']),
            ])
            ->withCount(['employees' => fn ($q) => $q
                ->where('is_active', true)
                ->whereIn('user_id', $this->visibleStaffQuery($user, $siteAccess)->select('users.id'))])
            ->when($deptSearch !== '', fn ($q) => $q->where(fn ($i) => $i
                ->where('name', 'like', "%{$deptSearch}%")
                ->orWhere('code', 'like', "%{$deptSearch}%")))
            ->when($deptStatus === 'active', fn ($q) => $q->where('is_active', true))
            ->when($deptStatus === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25, ['*'], 'dept_page')
            ->withQueryString();

        $departmentManagers = $this->currentVisibleStaffQuery($user, $siteAccess)
            ->orderBy('name')
            ->get(['id', 'name']);
        $departmentParents = HrDepartment::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
        $canDept = $user->canDo('hr.settings.manage') || $user->canDo('hr.employees.manage');

        // --- Org chart tab (folds /hr/orgchart) ---
        $orgProfiles = HrEmployeeProfile::query()
            ->whereIn('user_id', $this->currentVisibleStaffQuery($user, $siteAccess)->select('users.id'))
            ->with([
                'user:id,name',
                'primarySite' => fn ($query) => $query
                    ->whereIn('sites.id', $accessibleSiteIds)
                    ->select(['sites.id', 'sites.name']),
            ])
            ->orderBy('position_title')
            ->get();
        $orgHierarchy = app(OrgChartService::class)->getHierarchy($orgProfiles);
        $orgPeople = $orgProfiles
            ->map(fn ($p) => [
                'user_id' => $p->user_id,
                'name' => $p->user?->name ?? 'Unknown',
                'position_title' => $p->position_title,
                'manager_user_id' => $p->manager_user_id,
            ])
            ->values();
        $canOrgManage = $user->canDo('hr.orgchart.manage') || $user->canDo('hr.employees.manage');

        // --- "Needs attention" triage (drill-down modal from the hero chips) ---
        $triage = $this->buildTriage($user, $siteAccess);

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
    private function buildTriage(User $viewer, UserSiteAccessService $siteAccess): array
    {
        // Compliance — expired first, then expiring soon, soonest expiry up top.
        $compliance = HrStaffComplianceStatus::query()
            ->whereIn('user_id', $this->visibleStaffQuery($viewer, $siteAccess)->select('users.id'))
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

        $probation = $this->visibleProfilesQuery($viewer, $siteAccess)
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
                ->where('is_active', true));
        $siteAccess->applyHistoricalStaffSiteScope($invites, $viewer);
        $invites = $invites
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

    /** @return Builder<User> */
    private function visibleStaffQuery(User $viewer, UserSiteAccessService $siteAccess): Builder
    {
        $query = User::query()->staff();
        $siteAccess->applyHistoricalStaffSiteScope($query, $viewer);

        return $query;
    }

    /** @return Builder<User> */
    private function currentVisibleStaffQuery(User $viewer, UserSiteAccessService $siteAccess): Builder
    {
        $query = User::query();
        $siteAccess->applyStaffScope($query, $viewer);

        return $query;
    }

    /** @return Builder<HrEmployeeProfile> */
    private function visibleProfilesQuery(
        User $viewer,
        UserSiteAccessService $siteAccess,
        bool $includeArchived = false,
    ): Builder {
        $query = $includeArchived
            ? HrEmployeeProfile::withTrashed()
            : HrEmployeeProfile::query();

        return $query->whereIn(
            'user_id',
            $this->visibleStaffQuery($viewer, $siteAccess)->select('users.id'),
        );
    }

    private function assertProfileReadAccess(
        User $viewer,
        HrEmployeeProfile $profile,
        ?UserSiteAccessService $siteAccess = null,
    ): void {
        $siteAccess ??= app(UserSiteAccessService::class);
        $visibleProfile = $this->visibleProfilesQuery($viewer, $siteAccess, includeArchived: true)
            ->whereKey($profile->id)
            ->exists();

        abort_unless($visibleProfile, 404);
    }

    private function assertProfileMutationAccess(
        User $viewer,
        HrEmployeeProfile $profile,
        ?UserSiteAccessService $siteAccess = null,
        bool $allowArchived = false,
    ): void {
        abort_if($profile->trashed() && ! $allowArchived, 404);
        $siteAccess ??= app(UserSiteAccessService::class);
        $this->assertProfileReadAccess($viewer, $profile, $siteAccess);

        $assignedSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn ($siteId) => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn ($siteId) => (int) $siteId)
            ->unique()
            ->values();

        abort_unless(
            $assignedSiteIds->isNotEmpty()
                && $assignedSiteIds->diff($siteAccess->accessibleSiteIds($viewer))->isEmpty(),
            404,
        );
    }

    /** @return Builder<HrDepartment> */
    private function accessibleDepartmentsQuery(
        User $viewer,
        UserSiteAccessService $siteAccess,
    ): Builder {
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($viewer);

        return HrDepartment::query()
            ->where('is_active', true)
            ->where(function (Builder $query) use ($accessibleSiteIds): void {
                $query->whereDoesntHave('sites');
                if ($accessibleSiteIds !== []) {
                    $query->orWhereHas('sites', fn (Builder $siteQuery) => $siteQuery
                        ->whereIn('sites.id', $accessibleSiteIds));
                }
            });
    }

    private function invalidSelection(string $field): never
    {
        throw ValidationException::withMessages([
            $field => "The selected {$field} is invalid.",
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function shapedEmploymentHistory(mixed $history): array
    {
        return collect(is_array($history) ? $history : [])
            ->filter(fn ($entry) => is_array($entry))
            ->map(fn (array $entry) => collect($entry)->only([
                'start_date',
                'end_date',
                'position_title',
                'position_role',
                'employment_type',
                'archived_at',
            ])->all())
            ->values()
            ->all();
    }

    /**
     * LOCK ORDER: all affected Users by ID, all affected Profiles by ID, then
     * Site/department/offer destinations by ID.
     *
     * @param  iterable<int>  $userIds
     * @param  iterable<int>  $profileIds
     * @return array{users: Collection<int, User>, profiles: Collection<int, HrEmployeeProfile>}
     */
    private function lockPeopleMutationGraph(iterable $userIds, iterable $profileIds = []): array
    {
        return app(PeopleMutationLockService::class)->lock($userIds, $profileIds);
    }

    /**
     * @param  array{users: Collection<int, User>, profiles: Collection<int, HrEmployeeProfile>}  $locks
     * @return array{0: User, 1: UserSiteAccessService}
     */
    private function lockedPeopleMutationActor(array $locks, int $actorId): array
    {
        $actor = $locks['users']->get($actorId);
        abort_unless($actor, 403);
        abort_unless($actor->canDo('hr.employees.manage'), 403);

        $actorProfile = $locks['profiles']->first(
            fn (HrEmployeeProfile $profile) => (int) $profile->user_id === (int) $actor->id,
        );
        $actor->setRelation(
            'hrEmployeeProfile',
            $actorProfile && ! $actorProfile->trashed() ? $actorProfile : null,
        );

        return [$actor, new UserSiteAccessService];
    }

    private function lockedCurrentAccessibleManager(
        int $managerUserId,
        User $actor,
        UserSiteAccessService $siteAccess,
        array $locks,
    ): ?User {
        $manager = $locks['users']->get($managerUserId);
        if (! $manager) {
            return null;
        }

        $managerProfile = $locks['profiles']->first(
            fn (HrEmployeeProfile $profile) => (int) $profile->user_id === (int) $manager->id,
        );
        $manager->setRelation(
            'hrEmployeeProfile',
            $managerProfile && ! $managerProfile->trashed() ? $managerProfile : null,
        );

        $managerQuery = User::query()->whereKey($manager->id);
        $siteAccess->applyStaffScope($managerQuery, $actor);

        return $managerQuery->exists() ? $manager : null;
    }

    private function hasLockedAccessibleAcceptedOffer(
        string $email,
        int $requestedSiteId,
        array $accessibleSiteIds,
    ): bool {
        if ($accessibleSiteIds === []) {
            return false;
        }

        $candidate = HrCandidate::query()
            ->where('personal_email', $email)
            ->lockForUpdate()
            ->first();
        if (! $candidate) {
            return false;
        }

        $application = HrApplication::query()
            ->where('candidate_id', $candidate->id)
            ->where('target_site_id', $requestedSiteId)
            ->whereIn('target_site_id', $accessibleSiteIds)
            ->whereIn('status', ['offer_accepted', 'onboarding', 'hired'])
            ->lockForUpdate()
            ->first();
        if (! $application) {
            return false;
        }

        return HrOffer::query()
            ->where('application_id', $application->id)
            ->where('approval_status', 'approved')
            ->where('response', 'accepted')
            ->where('primary_site_id', $requestedSiteId)
            ->whereIn('primary_site_id', $accessibleSiteIds)
            ->lockForUpdate()
            ->first() !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  resendInvite — (re)send a login invite from the triage modal */
    /* ------------------------------------------------------------------ */

    public function resendInvite(Request $request, HrEmployeeProfile $profile)
    {
        $viewer = $request->user();
        abort_unless($viewer?->canDo('hr.employees.manage'), 403);
        $actorId = (int) $request->user()->id;
        $targetUserId = (int) $profile->user_id;
        $profileId = (int) $profile->id;
        $invite = DB::transaction(function () use ($actorId, $profileId, $targetUserId): array {
            $locks = $this->lockPeopleMutationGraph([$actorId, $targetUserId], [$profileId]);
            [$viewer, $siteAccess] = $this->lockedPeopleMutationActor($locks, $actorId);
            $account = $locks['users']->get($targetUserId);
            $profile = $locks['profiles']->get($profileId);
            abort_unless($profile, 404);
            abort_unless($account && (int) $profile->user_id === (int) $account->id, 404);
            $this->assertProfileMutationAccess($viewer, $profile, $siteAccess);

            if ($account->approved_at !== null) {
                throw ValidationException::withMessages([
                    'invite' => 'This employee already has an active login and does not need another invitation.',
                ]);
            }

            return [
                'account_id' => (int) $account->id,
                'account_name' => $account->name,
                'profile_id' => (int) $profile->id,
            ];
        }, attempts: 3);

        DB::afterCommit(function () use ($invite): void {
            $account = User::query()->find($invite['account_id']);
            $profile = HrEmployeeProfile::query()->find($invite['profile_id']);
            if (! $account || ! $profile || $account->approved_at !== null) {
                return;
            }

            $token = Password::broker()->createToken($account);
            $account->notify(new EmployeeInviteNotification($token, $profile));
        });

        return back()->with('success', "Login invite sent to {$invite['account_name']}.");
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create a new employee (User + profile + role) */
    /* ------------------------------------------------------------------ */

    public function store(StoreEmployeeRequest $request, EmployeeIntakeService $intake)
    {
        $data = $request->validated();
        $actorId = (int) $request->user()->id;
        $managerUserId = isset($data['manager_user_id']) ? (int) $data['manager_user_id'] : null;
        $existingUserId = User::query()->where('email', $data['email'])->value('id');

        try {
            $profile = DB::transaction(function () use ($actorId, $data, $existingUserId, $intake, $managerUserId, $request): HrEmployeeProfile {
                $locks = $this->lockPeopleMutationGraph([$actorId, $existingUserId, $managerUserId]);
                [$actor, $siteAccess] = $this->lockedPeopleMutationActor($locks, $actorId);
                $accessibleSiteIds = $siteAccess->accessibleSiteIds($actor);

                $existingUser = $existingUserId ? $locks['users']->get((int) $existingUserId) : null;
                if ($existingUser && $existingUser->email !== $data['email']) {
                    throw ValidationException::withMessages([
                        'email' => 'This existing email cannot be linked through employee intake.',
                    ]);
                }
                $existingProfile = $existingUser
                    ? $locks['profiles']->first(
                        fn (HrEmployeeProfile $lockedProfile) => (int) $lockedProfile->user_id === (int) $existingUser->id,
                    )
                    : null;

                if ($existingProfile) {
                    abort_if($existingProfile->trashed(), 404);
                    $this->assertProfileMutationAccess($actor, $existingProfile, $siteAccess);
                    if (! $request->boolean('link_existing')) {
                        throw ValidationException::withMessages([
                            'email' => 'A staff member already uses this email. Enable “Link to existing record” to update their profile.',
                        ]);
                    }
                } elseif ($existingUser
                    && ! $this->hasLockedAccessibleAcceptedOffer(
                        $data['email'],
                        (int) $data['primary_site_id'],
                        $accessibleSiteIds,
                    )) {
                    throw ValidationException::withMessages([
                        'email' => 'This existing email cannot be linked through employee intake.',
                    ]);
                }

                if ($managerUserId
                    && ! $this->lockedCurrentAccessibleManager((int) $managerUserId, $actor, $siteAccess, $locks)) {
                    $this->invalidSelection('manager_user_id');
                }

                $primarySite = Site::query()
                    ->active()
                    ->notArchived()
                    ->whereKey($data['primary_site_id'])
                    ->whereIn('id', $accessibleSiteIds)
                    ->lockForUpdate()
                    ->first();
                if (! $primarySite) {
                    $this->invalidSelection('primary_site_id');
                }

                $department = null;
                if (! empty($data['department_id'])) {
                    $department = $this->accessibleDepartmentsQuery($actor, $siteAccess)
                        ->whereKey($data['department_id'])
                        ->lockForUpdate()
                        ->first();
                    if (! $department) {
                        $this->invalidSelection('department_id');
                    }
                }

                $positionTitle = $data['position_title'] ?? null;
                if (empty($positionTitle) && ! empty($data['position_id'])) {
                    $positionTitle = HrPosition::query()
                        ->whereKey($data['position_id'])
                        ->lockForUpdate()
                        ->value('title');
                }

                $roleName = $data['role'] ?? 'support_worker';

                return $intake->intake(
                    name: $data['name'],
                    email: $data['email'],
                    roleName: $roleName,
                    profileAttributes: [
                        'preferred_name' => $data['preferred_name'] ?? null,
                        'position_id' => $data['position_id'] ?? null,
                        'position_title' => $positionTitle ?: 'New starter',
                        'position_role' => $roleName,
                        'employment_type' => $data['employment_type'] ?? 'full_time',
                        'department_id' => $department?->id,
                        'department' => $department?->name,
                        'team' => HrEmployeeProfile::canonicalTeam($data['team'] ?? null),
                        'primary_site_id' => $primarySite->id,
                        'manager_user_id' => $managerUserId,
                        'start_date' => $data['start_date'] ?? now()->toDateString(),
                        'work_phone' => $data['work_phone'] ?? null,
                        'work_rights_status' => $data['work_rights_status'] ?? null,
                        'visa_type' => $data['visa_type'] ?? null,
                        'visa_expires_at' => $data['visa_expires_at'] ?? null,
                        'emergency_contacts' => $data['emergency_contacts'] ?? null,
                    ],
                    actorId: $actor->id,
                    startOnboarding: $request->boolean('start_onboarding', true),
                    sendInvite: $request->boolean('send_invite', false),
                    source: 'manual',
                    authorizedExistingUserId: $existingUser?->id,
                );
            }, attempts: 3);
        } catch (\InvalidArgumentException $e) {
            $field = str_contains(strtolower($e->getMessage()), 'existing') ? 'email' : 'role';

            return back()->withInput()->withErrors([$field => $e->getMessage()]);
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
        $viewer = $request->user();
        abort_unless($viewer?->canDo('hr.employees.manage'), 403);
        // Conceal hidden direct objects before payload validation so malformed
        // requests cannot distinguish an inaccessible ID from a missing one.
        $this->assertProfileMutationAccess($viewer, $profile, new UserSiteAccessService);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $actorId = (int) $request->user()->id;
        $targetUserId = (int) $profile->user_id;
        $profileId = (int) $profile->id;
        $profile = DB::transaction(function () use ($actorId, $data, $profileId, $targetUserId): HrEmployeeProfile {
            $locks = $this->lockPeopleMutationGraph([$actorId, $targetUserId], [$profileId]);
            [$viewer, $siteAccess] = $this->lockedPeopleMutationActor($locks, $actorId);
            $account = $locks['users']->get($targetUserId);
            $profile = $locks['profiles']->get($profileId);
            abort_unless($profile, 404);
            abort_unless($account && (int) $profile->user_id === (int) $account->id, 404);
            $this->assertProfileMutationAccess($viewer, $profile, $siteAccess);

            $profile->update(['is_active' => $data['is_active']]);

            if ($data['is_active'] && $account && is_null($account->approved_at)) {
                $account->forceFill(['approved_at' => now()])->save();
                AuditLogger::log('user.login_reactivated', $account, [
                    'actor_id' => $viewer->id,
                    'employee_profile_id' => $profile->id,
                    'reason' => 'employee_profile_reactivated',
                ]);
            }

            $profile->setRelation('user', $account);

            return $profile;
        }, attempts: 3);

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
        $siteAccess = app(UserSiteAccessService::class);
        // Preserve enumeration-safe 404s before field validation, then repeat
        // the authorization against locked rows inside the transaction.
        $this->assertProfileMutationAccess($user, $profile, $siteAccess, allowArchived: true);

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'position_title' => ['nullable', 'string', 'max:255'],
            'position_role' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', 'string', 'in:full_time,part_time,casual,fixed_term,contractor,permanent'],
            'primary_site_id' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, \Closure $fail) use ($siteAccess, $user): void {
                    if (! in_array((int) $value, $siteAccess->accessibleSiteIds($user), true)) {
                        $fail('The selected primary site is invalid.');
                    }
                },
            ],
            'hours_per_week' => ['nullable', 'numeric', 'min:0', 'max:168'],
            'send_invite' => ['nullable', 'boolean'],
            'start_onboarding' => ['nullable', 'boolean'],
        ]);

        $attributes = collect($data)
            ->only(['start_date', 'position_title', 'position_role', 'employment_type', 'primary_site_id', 'hours_per_week'])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->all();
        $actorId = (int) $request->user()->id;
        $targetUserId = (int) $profile->user_id;
        $profileId = (int) $profile->id;

        try {
            $profile = DB::transaction(function () use ($actorId, $attributes, $intake, $profileId, $request, $targetUserId): HrEmployeeProfile {
                $locks = $this->lockPeopleMutationGraph([$actorId, $targetUserId], [$profileId]);
                [$user, $siteAccess] = $this->lockedPeopleMutationActor($locks, $actorId);
                $profileUser = $locks['users']->get($targetUserId);
                $profile = $locks['profiles']->get($profileId);
                abort_unless($profile, 404);
                abort_unless($profileUser && (int) $profile->user_id === (int) $profileUser->id, 404);
                $profile->setRelation('user', $profileUser);
                $this->assertProfileMutationAccess($user, $profile, $siteAccess, allowArchived: true);

                if ($profile->is_active) {
                    throw new \InvalidArgumentException("{$profile->user?->name} is already active — nothing to re-hire.");
                }

                if (! empty($attributes['primary_site_id'])) {
                    $site = Site::query()
                        ->whereKey($attributes['primary_site_id'])
                        ->whereIn('id', $siteAccess->accessibleSiteIds($user))
                        ->lockForUpdate()
                        ->first();
                    if (! $site) {
                        $this->invalidSelection('primary_site_id');
                    }
                }

                if ($profile->trashed()) {
                    $profile->restore();
                }

                return $intake->rehire(
                    $profile,
                    $attributes,
                    $user->id,
                    sendInvite: $request->boolean('send_invite', true),
                    startOnboarding: $request->boolean('start_onboarding', true),
                );
            }, attempts: 3);
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

        $data = $request->validate([
            'action' => ['required', 'string', 'in:deactivate,reactivate,assign_site,assign_department,assign_manager'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'site_id' => ['required_if:action,assign_site', 'nullable', 'integer'],
            'department_id' => ['required_if:action,assign_department', 'nullable', 'integer'],
            'manager_user_id' => ['required_if:action,assign_manager', 'nullable', 'integer'],
        ]);

        // Update model-by-model (not a mass query update): bulk query updates
        // skip Eloquent events, so AuditableChanges would never log the rows
        // and the change would be invisible in /hr/settings/audit-log.
        $profileIds = collect($data['ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $actorId = (int) $request->user()->id;
        $targetUserIds = HrEmployeeProfile::withTrashed()
            ->whereIn('id', $profileIds)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);
        $managerUserId = $data['action'] === 'assign_manager'
            ? (int) $data['manager_user_id']
            : null;
        $count = DB::transaction(function () use ($actorId, $data, $managerUserId, $profileIds, $targetUserIds): int {
            $locks = $this->lockPeopleMutationGraph(
                [$actorId, ...$targetUserIds, $managerUserId],
                $profileIds,
            );
            [$user, $siteAccess] = $this->lockedPeopleMutationActor($locks, $actorId);
            $profiles = $profileIds
                ->map(fn (int $profileId) => $locks['profiles']->get($profileId))
                ->filter()
                ->values();
            abort_unless($profiles->count() === $profileIds->count(), 404);
            foreach ($profiles as $profile) {
                $this->assertProfileMutationAccess($user, $profile, $siteAccess);
            }

            $department = null;
            if ($data['action'] === 'assign_site') {
                $site = Site::query()
                    ->whereKey($data['site_id'])
                    ->whereIn('id', $siteAccess->accessibleSiteIds($user))
                    ->lockForUpdate()
                    ->first();
                if (! $site) {
                    $this->invalidSelection('site_id');
                }
            }

            if ($data['action'] === 'assign_department') {
                $department = $this->accessibleDepartmentsQuery($user, $siteAccess)
                    ->whereKey($data['department_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $department) {
                    $this->invalidSelection('department_id');
                }
            }

            if ($data['action'] === 'assign_manager') {
                if (! $this->lockedCurrentAccessibleManager(
                    $managerUserId,
                    $user,
                    $siteAccess,
                    $locks,
                )) {
                    $this->invalidSelection('manager_user_id');
                }
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

            $profiles->each(fn (HrEmployeeProfile $profile) => $profile->update($attributes));

            return $profiles->count();
        }, attempts: 3);

        return back()->with('success', "{$count} ".($count === 1 ? 'person' : 'people').' updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — tabbed profile with related data */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($user);
        $this->assertProfileReadAccess($user, $profile, $siteAccess);
        $accessibleDepartmentIds = $this->accessibleDepartmentsQuery($user, $siteAccess)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

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
                ->whereIn('site_id', $accessibleSiteIds)
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
            $mp = HrEmployeeProfile::where('user_id', $profile->manager_user_id)
                ->whereIn('user_id', $this->currentVisibleStaffQuery($user, $siteAccess)->select('users.id'))
                ->with('user:id,name')
                ->first();
            if ($mp) {
                $manager = ['id' => $mp->id, 'name' => $mp->user?->name ?? 'Unknown', 'position_title' => $mp->position_title, 'profile_photo_path' => $mp->profile_photo_path];
            }
        }

        // Direct reports
        $directReports = HrEmployeeProfile::where('manager_user_id', $userId)
            ->whereIn('user_id', $this->currentVisibleStaffQuery($user, $siteAccess)->select('users.id'))
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

        // Read-only cross-module projection. Security & Devices, Fleet & Assets,
        // and IT keep lifecycle ownership; HR receives only access-approved rows.
        $equipmentAccess = app(HrEquipmentAccessProjectionService::class)->present($user, $profile);

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
                'department' => $profile->department_id === null
                    || in_array((int) $profile->department_id, $accessibleDepartmentIds, true)
                        ? ($profile->departmentRelation?->name ?? $profile->department)
                        : null,
                'team' => $profile->team,
                'is_active' => (bool) $profile->is_active,
                'start_date' => $profile->start_date?->toDateString(),
                'end_date' => $profile->end_date?->toDateString(),
                'probation_end_date' => $profile->probation_end_date?->toDateString(),
                'hours_per_week' => $profile->hours_per_week,
                'employment_history' => $this->shapedEmploymentHistory($profile->employment_history),
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
                'primary_site' => $profile->primarySite
                    && in_array((int) $profile->primary_site_id, $accessibleSiteIds, true)
                        ? ['id' => $profile->primarySite->id, 'name' => $profile->primarySite->name]
                        : null,
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
            'equipmentAccess' => $equipmentAccess,
            'policyAttestations' => $policyAttestations,
            'safeWorkProcedures' => $this->employeeProcedures($user, $profile),
            ...($canViewInjuries ? ['workplaceInjuries' => $workplaceInjuries] : []),
            // Re-hire wizard site options — only needed when the viewer can
            // manage AND the profile is a former employee.
            'rehireSites' => $user->canDo('hr.employees.manage') && ! $profile->is_active
                ? Site::query()
                    ->whereIn('id', $accessibleSiteIds)
                    ->orderBy('name')
                    ->get(['id', 'name'])
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
        $siteAccess = app(UserSiteAccessService::class);
        $accessibleSiteIds = $siteAccess->accessibleSiteIds($user);
        $this->assertProfileReadAccess($user, $profile, $siteAccess);
        $accessibleDepartmentIds = $this->accessibleDepartmentsQuery($user, $siteAccess)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $profile->load('user:id,name,email');

        $profilePayload = [
            'id' => $profile->id,
            'employee_number' => $profile->employee_number,
            'position_title' => $profile->position_title,
            'employment_type' => $profile->employment_type,
            'contract_type' => $profile->contract_type,
            'department_id' => in_array((int) $profile->department_id, $accessibleDepartmentIds, true)
                ? $profile->department_id
                : null,
            'team' => $profile->team,
            'work_rights_status' => $profile->work_rights_status,
            'visa_type' => $profile->visa_type,
            'visa_expires_at' => $profile->visa_expires_at?->toDateString(),
            'is_active' => (bool) $profile->is_active,
            'start_date' => $profile->start_date?->toDateString(),
            'end_date' => $profile->end_date?->toDateString(),
            'probation_end_date' => $profile->probation_end_date?->toDateString(),
            'hours_per_week' => $profile->hours_per_week !== null ? (float) $profile->hours_per_week : null,
            'primary_site_id' => in_array((int) $profile->primary_site_id, $accessibleSiteIds, true)
                ? $profile->primary_site_id
                : null,
            'emergency_contacts' => $profile->emergency_contacts ?? [],
            'notes' => $profile->notes,
            'user' => [
                'id' => $profile->user->id,
                'name' => $profile->user->name,
                'email' => $profile->user->email,
            ],
        ];
        if ($user->canDo('hr.employees.viewFinancial')) {
            $profilePayload = [
                ...$profilePayload,
                'hourly_rate' => $profile->hourly_rate !== null ? (float) $profile->hourly_rate : null,
                'annual_salary' => $profile->annual_salary !== null ? (float) $profile->annual_salary : null,
                'pay_frequency' => $profile->pay_frequency,
            ];
        }

        $sites = Site::orderBy('name')
            ->whereIn('id', $accessibleSiteIds)
            ->get(['id', 'name']);

        $departments = $this->accessibleDepartmentsQuery($user, $siteAccess)
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
        $validated = $request->validated();
        $actorId = (int) $request->user()->id;
        $targetUserId = (int) $profile->user_id;
        $profileId = (int) $profile->id;
        $managerUserId = ! empty($validated['manager_user_id'])
            ? (int) $validated['manager_user_id']
            : null;
        DB::transaction(function () use ($actorId, $managerUserId, $profileId, $provisioningWorkflows, $targetUserId, $validated): void {
            $locks = $this->lockPeopleMutationGraph(
                [$actorId, $targetUserId, $managerUserId],
                [$profileId],
            );
            [$user, $siteAccess] = $this->lockedPeopleMutationActor($locks, $actorId);
            $profileUser = $locks['users']->get($targetUserId);
            $profile = $locks['profiles']->get($profileId);
            abort_unless($profile, 404);
            abort_unless($profileUser && (int) $profile->user_id === (int) $profileUser->id, 404);
            $this->assertProfileMutationAccess($user, $profile, $siteAccess);

            $financialFields = ['hourly_rate', 'annual_salary', 'pay_frequency', 'bank_account', 'ird_number', 'tax_code', 'kiwisaver_rate'];
            if (collect($financialFields)->contains(fn (string $field) => array_key_exists($field, $validated))
                && ! $user->canDo('hr.employees.viewFinancial')) {
                throw ValidationException::withMessages([
                    'hourly_rate' => 'You do not have permission to update financial details.',
                ]);
            }

            $finalPrimarySiteId = array_key_exists('primary_site_id', $validated)
                ? $validated['primary_site_id']
                : $profile->primary_site_id;
            $finalSecondarySiteIds = array_key_exists('secondary_site_ids', $validated)
                ? ($validated['secondary_site_ids'] ?? [])
                : ($profile->secondary_site_ids ?? []);
            $finalSiteIds = collect([$finalPrimarySiteId, ...$finalSecondarySiteIds])
                ->filter(fn ($siteId) => is_numeric($siteId) && (int) $siteId > 0)
                ->map(fn ($siteId) => (int) $siteId)
                ->unique()
                ->values();
            if ($finalSiteIds->isEmpty()) {
                throw ValidationException::withMessages([
                    'primary_site_id' => 'An accessible primary or secondary site is required.',
                ]);
            }

            if ($managerUserId
                && ! $this->lockedCurrentAccessibleManager(
                    $managerUserId,
                    $user,
                    $siteAccess,
                    $locks,
                )) {
                $this->invalidSelection('manager_user_id');
            }

            $lockedSiteIds = Site::query()
                ->active()
                ->notArchived()
                ->whereIn('id', $finalSiteIds)
                ->whereIn('id', $siteAccess->accessibleSiteIds($user))
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($siteId) => (int) $siteId);
            if ($lockedSiteIds->count() !== $finalSiteIds->count()) {
                $this->invalidSelection('primary_site_id');
            }

            if (array_key_exists('department_id', $validated)) {
                $department = $validated['department_id']
                    ? $this->accessibleDepartmentsQuery($user, $siteAccess)
                        ->whereKey($validated['department_id'])
                        ->lockForUpdate()
                        ->first()
                    : null;
                if ($validated['department_id'] && ! $department) {
                    $this->invalidSelection('department_id');
                }
                $validated['department'] = $department?->name;
            }

            if (array_key_exists('team', $validated)) {
                $validated['team'] = HrEmployeeProfile::canonicalTeam($validated['team']);
            }
            $validated['updated_by'] = $user->id;

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
        }, attempts: 3);

        return redirect()->back()->with('success', 'Employee profile updated successfully.');
    }
}
