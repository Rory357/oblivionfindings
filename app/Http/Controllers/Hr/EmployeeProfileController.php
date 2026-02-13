<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $tenantId = null;
        $search = trim((string) $request->query('q', ''));
        $status = $request->query('status'); // 'active', 'inactive', or null for all
        $siteId = $request->query('site_id');

        $profiles = HrEmployeeProfile::with([
                'user:id,name,email',
                'primarySite:id,name',
            ])
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
            ))
            ->when($status === 'active', fn ($q) => $q->active())
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($siteId, fn ($q) => $q->atSite((int) $siteId))
            ->orderBy('created_at', 'desc')
            ->paginate(20)
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

    public function update(Request $request, HrEmployeeProfile $profile)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.employees.manage'), 403);

        $validated = $request->validate([
            'employee_number'    => ['nullable', 'string', 'max:50'],
            'date_of_birth'      => ['nullable', 'date', 'before:today'],
            'gender'             => ['nullable', 'string', 'max:50'],
            'ethnicity'          => ['nullable', 'string', 'max:100'],
            'personal_email'     => ['nullable', 'email', 'max:255'],
            'personal_phone'     => ['nullable', 'string', 'max:50'],
            'home_address'       => ['nullable', 'string', 'max:1000'],
            'work_email'         => ['nullable', 'email', 'max:255'],
            'work_phone'         => ['nullable', 'string', 'max:50'],
            'position_title'     => ['sometimes', 'required', 'string', 'max:255'],
            'position_role'      => ['nullable', 'string', 'max:100'],
            'employment_type'    => ['sometimes', 'required', 'string', Rule::in(['full_time', 'part_time', 'casual', 'fixed_term', 'contractor'])],
            'contract_type'      => ['nullable', 'string', Rule::in(['permanent', 'fixed_term', 'casual', 'contractor'])],
            'hours_per_week'     => ['nullable', 'numeric', 'min:0', 'max:60'],
            'hourly_rate'        => ['nullable', 'numeric', 'min:0'],
            'annual_salary'      => ['nullable', 'numeric', 'min:0'],
            'pay_frequency'      => ['nullable', 'string', Rule::in(['weekly', 'fortnightly', 'monthly'])],
            'start_date'         => ['nullable', 'date'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:start_date'],
            'probation_end_date' => ['nullable', 'date'],
            'termination_reason' => ['nullable', 'string', 'max:1000'],
            'is_active'          => ['sometimes', 'boolean'],
            'primary_site_id'    => ['nullable', 'integer', 'exists:sites,id'],
            'secondary_site_ids' => ['nullable', 'array'],
            'secondary_site_ids.*' => ['integer', 'exists:sites,id'],
            'emergency_contacts' => ['nullable', 'array'],
            'bank_account'       => ['nullable', 'string', 'max:255'],
            'ird_number'         => ['nullable', 'string', 'max:20'],
            'tax_code'           => ['nullable', 'string', 'max:10'],
            'kiwisaver_rate'     => ['nullable', 'numeric', 'min:0', 'max:10'],
            'can_drive_clients'  => ['sometimes', 'boolean'],
            'is_first_aider'     => ['sometimes', 'boolean'],
            'is_fire_warden'     => ['sometimes', 'boolean'],
            'notes'              => ['nullable', 'string', 'max:5000'],
            'restricted_notes'   => ['nullable', 'string', 'max:5000'],
        ]);

        $validated['updated_by'] = $user->id;
        $profile->update($validated);

        return redirect()->back()->with('success', 'Employee profile updated successfully.');
    }
}
