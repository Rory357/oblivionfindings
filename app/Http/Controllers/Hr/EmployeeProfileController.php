<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreEmployeeRequest;
use App\Http\Requests\Hr\UpdateEmployeeProfileRequest;
use App\Domain\Hr\Models\HrAssetAssignment;
use App\Domain\Hr\Models\HrCase;
use App\Domain\Hr\Models\HrCompetencyAssessment;
use App\Domain\Hr\Models\HrCourseEnrollment;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrEmployeeSkill;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrProbationReview;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffBackgroundCheck;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class EmployeeProfileController extends Controller
{
    use ResolvesHrTenant;

    /* ------------------------------------------------------------------ */
    /*  Index — paginated employee list                                    */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);

        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status'); // 'active', 'inactive', or null for all
        $siteId = $request->query('site_id');
        $department = $request->query('department');
        $employmentType = $request->query('employment_type');

        $profiles = User::query()
            ->staff()
            ->with([
                'hrEmployeeProfile.primarySite:id,name',
            ])
            ->when($search !== '', fn ($q) =>
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
            )
            ->when($status === 'active', fn ($q) =>
                $q->where(function ($statusQuery) {
                    $statusQuery
                        ->whereDoesntHave('hrEmployeeProfile')
                        ->orWhereHas('hrEmployeeProfile', fn ($profile) => $profile->where('is_active', true));
                })
            )
            ->when($status === 'inactive', fn ($q) =>
                $q->whereHas('hrEmployeeProfile', fn ($profile) => $profile->where('is_active', false))
            )
            ->when($siteId, fn ($q) =>
                $q->whereHas('hrEmployeeProfile', function ($profileQuery) use ($siteId) {
                    $profileQuery->where(function ($siteQuery) use ($siteId) {
                        $siteQuery
                            ->where('primary_site_id', (int) $siteId)
                            ->orWhereJsonContains('secondary_site_ids', (int) $siteId);
                    });
                })
            )
            ->when($department, fn ($q) =>
                $q->whereHas('hrEmployeeProfile', fn ($p) => $p->where('department_id', (int) $department))
            )
            ->when($employmentType, fn ($q) =>
                $q->whereHas('hrEmployeeProfile', fn ($p) => $p->where('employment_type', $employmentType))
            )
            ->orderBy('name')
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

        // Employment type breakdown
        $typeCounts = HrEmployeeProfile::where('is_active', true)
            ->selectRaw("employment_type, count(*) as count")
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
        // Resolve a real tenant id — users carry no tenant_id column, so the
        // legacy $user->tenant_id was always null (forTenant(null) → empty).
        $tenantId = $this->resolveHrTenantIdForUser($user);
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
            'is_active' => (bool) $pos->is_active,
            'description' => $pos->description,
            'requirements' => $pos->requirements,
            'reports_to_position_id' => $pos->reports_to_position_id,
        ]);

        $parentPositions = HrPosition::forTenant($tenantId)
            ->where('is_active', true)
            ->orderBy('title')
            ->get(['id', 'title', 'code']);

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
            'filters' => [
                'q' => $search,
                'status' => $status,
                'site_id' => $siteId,
                'department' => $department,
                'employment_type' => $employmentType,
            ],
            'summary' => [
                'active' => $activeCount,
                'inactive' => $inactiveCount,
                'new_hires' => $newHires,
                'on_probation' => $onProbation,
                'compliance_alerts' => $complianceAlerts,
                'type_counts' => $typeCounts,
            ],
            'can' => [
                'manage' => $user->canDo('hr.employees.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — create a new employee (User + profile + role)               */
    /* ------------------------------------------------------------------ */

    public function store(StoreEmployeeRequest $request)
    {
        $actor = $request->user();
        $data = $request->validated();
        $roleName = $data['role'] ?? 'support_worker';

        $positionTitle = $data['position_title'] ?? null;
        if (empty($positionTitle) && ! empty($data['position_id'])) {
            $positionTitle = HrPosition::find($data['position_id'])?->title;
        }

        $profile = DB::transaction(function () use ($data, $actor, $roleName, $positionTitle) {
            $newUser = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $roleName,
                'password' => bcrypt(Str::random(40)),
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            $role = Role::where('name', $roleName)->first();
            if ($role) {
                $newUser->roles()->syncWithoutDetaching([$role->id]);
            }

            // hr_employee_profiles requires position_title / position_role /
            // employment_type / start_date (NOT NULL). Quick-add fills sensible
            // defaults the user can refine on the profile.
            return HrEmployeeProfile::create([
                'tenant_id' => $actor->tenant_id ?? 1,
                'user_id' => $newUser->id,
                'employee_number' => $this->generateEmployeeNumber(),
                'preferred_name' => $data['preferred_name'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'position_title' => $positionTitle ?: 'New starter',
                'position_role' => $roleName,
                'employment_type' => $data['employment_type'] ?? 'full_time',
                'department' => $data['department'] ?? null,
                'primary_site_id' => $data['primary_site_id'] ?? null,
                'manager_user_id' => $data['manager_user_id'] ?? null,
                'start_date' => $data['start_date'] ?? now()->toDateString(),
                'work_email' => $data['email'],
                'work_phone' => $data['work_phone'] ?? null,
                'is_active' => true,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });

        return redirect()
            ->route('hr.people.show', $profile->id)
            ->with('success', "{$data['name']} has been added to your team.");
    }

    private function generateEmployeeNumber(): string
    {
        $next = (int) (HrEmployeeProfile::withTrashed()->max('id') ?? 0) + 1;

        return 'EMP-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — tabbed profile with related data                            */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.viewAny'), 403);

        $profile->load([
            'user:id,name,email',
            'primarySite:id,name',
            'departmentRelation:id,name',
            'documents',
            'offer:id,application_id,position_title,proposed_start_date,employment_type',
        ]);

        $userId = $profile->user_id;

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
        $pips = HrPerformanceImprovementPlan::where('employee_profile_id', $profile->id)
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
                'proficiency_level' => $a->proficiency_level, 'target_level' => $a->target_level,
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
                'employment_type' => $profile->employment_type,
                'contract_type' => $profile->contract_type,
                'department' => $profile->departmentRelation?->name ?? $profile->department,
                'team' => $profile->team,
                'is_active' => (bool) $profile->is_active,
                'start_date' => $profile->start_date?->toDateString(),
                'end_date' => $profile->end_date?->toDateString(),
                'probation_end_date' => $profile->probation_end_date?->toDateString(),
                'hours_per_week' => $profile->hours_per_week,
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
            'can' => [
                'manage' => $user->canDo('hr.employees.manage'),
                'viewSensitive' => $user->canDo('hr.employees.viewRestricted'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Edit                                                               */
    /* ------------------------------------------------------------------ */

    public function edit(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        $profile->load('user:id,name,email');

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
            'profile' => $profile,
            'sites' => $sites,
            'departments' => $departments,
            'employmentTypes' => $options(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor']),
            'contractTypes' => $options(['permanent', 'fixed_term', 'casual', 'contractor']),
            'payFrequencies' => $options(['weekly', 'fortnightly', 'monthly']),
            'workRightsStatuses' => $options(['citizen', 'permanent_resident', 'resident_visa', 'work_visa', 'student_visa', 'other']),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update                                                             */
    /* ------------------------------------------------------------------ */

    public function update(UpdateEmployeeProfileRequest $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();

        $validated = $request->validated();
        $validated['updated_by'] = $user->id;
        $profile->update($validated);

        return redirect()->back()->with('success', 'Employee profile updated successfully.');
    }
}
