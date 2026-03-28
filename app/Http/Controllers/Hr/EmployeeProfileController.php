<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\UpdateEmployeeProfileRequest;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeProfileController extends Controller
{
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
                    'is_active' => $profile ? (bool) $profile->is_active : true,
                    'start_date' => $profile?->start_date?->toDateString(),
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

        return Inertia::render('hr/employees/index', [
            'profiles' => $profiles,
            'sites' => $sites,
            'filters' => [
                'q' => $search,
                'status' => $status,
                'site_id' => $siteId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.employees.manage'),
            ],
        ]);
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
            'documents',
            'offer:id,application_id,position_title,proposed_start_date,employment_type',
        ]);

        // Compliance status for this employee
        $rawStatuses = HrStaffComplianceStatus::where('user_id', $profile->user_id)
            ->with('requirement:id,code,name,category,check_type,hard_stop')
            ->get();

        // Transform to match frontend ComplianceStatus interface
        $complianceStatuses = $rawStatuses->map(fn ($s) => [
            'id'              => $s->id,
            'requirement_name' => $s->requirement?->name ?? '',
            'requirement_type' => $s->requirement?->check_type ?? '',
            'status'          => $s->status,
            'expiry_date'     => $s->expires_at?->toDateString(),
            'completed_date'  => $s->valid_from?->toDateString(),
            'evidence_url'    => null,
        ])->values();

        $complianceSummary = [
            'compliant'     => $rawStatuses->where('status', 'compliant')->count(),
            'expiring_soon' => $rawStatuses->where('status', 'expiring_soon')->count(),
            'expired'       => $rawStatuses->where('status', 'expired')->count(),
            'not_started'   => $rawStatuses->where('status', 'not_started')->count(),
            'total'         => $rawStatuses->count(),
        ];

        // Leave balances for current year
        $leaveBalances = HrLeaveBalance::where('user_id', $profile->user_id)
            ->where('year', now()->year)
            ->get()
            ->map(fn ($lb) => [
                'id'           => $lb->id,
                'leave_type'   => $lb->leave_type,
                'accrued_hours' => (float) $lb->accrued_hours,
                'used_hours'   => (float) $lb->used_hours,
                'balance_hours' => (float) $lb->balance_hours,
                'as_at_date'   => $lb->last_synced_at?->toDateString() ?? now()->toDateString(),
            ]);

        // Onboarding checklists — transform to match frontend interface
        $onboardingChecklists = HrOnboardingChecklist::where('employee_profile_id', $profile->id)
            ->with('tasks')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($cl) => [
                'id'   => $cl->id,
                'name' => $cl->template_key ?? 'Onboarding Checklist',
                'items' => $cl->tasks->map(fn ($t) => [
                    'key'          => (string) $t->id,
                    'label'        => $t->title,
                    'done'         => $t->status === 'completed',
                    'completed_at' => $t->completed_at?->toDateString(),
                ])->values(),
                'completed_at' => $cl->completed_at?->toDateString(),
            ]);

        return Inertia::render('hr/employees/show', [
            'profile' => $profile,
            'complianceStatuses' => $complianceStatuses,
            'complianceSummary' => $complianceSummary,
            'leaveBalances' => $leaveBalances,
            'onboardingChecklists' => $onboardingChecklists,
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

        return Inertia::render('hr/employees/edit', [
            'profile' => $profile,
            'sites' => $sites,
            'employmentTypes' => ['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'],
            'contractTypes' => ['permanent', 'fixed_term', 'casual', 'contractor'],
            'payFrequencies' => ['weekly', 'fortnightly', 'monthly'],
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
