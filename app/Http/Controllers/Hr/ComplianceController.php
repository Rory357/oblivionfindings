<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Services\ComplianceMatrixService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ComplianceController extends Controller
{
    public function __construct(
        private readonly ComplianceMatrixService $complianceMatrixService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — staff compliance table with per-user breakdown             */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $search = trim((string) $request->query('q', ''));
        $statusFilter = $request->query('status');
        $requirementId = $request->query('requirement_id');

        // Requirements list for filter dropdown
        $requirements = HrComplianceRequirement::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'check_type as type']);

        // Build per-staff compliance stats from hr_staff_compliance_status
        $totalRequirements = HrComplianceRequirement::where('is_active', true)->count();

        // Get active staff user IDs from employee profiles
        $activeStaffQuery = HrEmployeeProfile::where('is_active', true);
        $activeStaffUserIds = (clone $activeStaffQuery)->pluck('user_id');

        // Build paginated per-user compliance data
        $staffQuery = User::whereIn('id', $activeStaffUserIds)
            ->when($search !== '', fn ($q) => $q->where(function ($q2) use ($search) {
                $q2->where('name', 'like', "%{$search}%")
                   ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($requirementId, fn ($q) => $q->whereHas('complianceStatuses', fn ($cs) =>
                $cs->where('requirement_id', $requirementId)
            ));

        // Apply status filter
        if ($statusFilter === 'fully_compliant') {
            $staffQuery->whereDoesntHave('complianceStatuses', fn ($q) =>
                $q->whereIn('status', ['expired', 'expiring_soon', 'not_started'])
            );
        } elseif ($statusFilter === 'has_expired') {
            $staffQuery->whereHas('complianceStatuses', fn ($q) =>
                $q->where('status', 'expired')
            );
        } elseif ($statusFilter === 'has_expiring') {
            $staffQuery->whereHas('complianceStatuses', fn ($q) =>
                $q->where('status', 'expiring_soon')
            );
        } elseif ($statusFilter === 'incomplete') {
            $staffQuery->whereHas('complianceStatuses', fn ($q) =>
                $q->where('status', 'not_started')
            );
        }

        $staffPaginated = $staffQuery
            ->withCount([
                'complianceStatuses as compliant_count'      => fn ($q) => $q->where('status', 'compliant'),
                'complianceStatuses as expired_count'         => fn ($q) => $q->where('status', 'expired'),
                'complianceStatuses as expiring_soon_count'   => fn ($q) => $q->where('status', 'expiring_soon'),
                'complianceStatuses as not_started_count'     => fn ($q) => $q->where('status', 'not_started'),
            ])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Transform paginated data to match frontend StaffStatus interface
        $staffPaginated->getCollection()->transform(function ($staffUser) use ($totalRequirements) {
            $total = max($staffUser->compliant_count + $staffUser->expired_count + $staffUser->expiring_soon_count + $staffUser->not_started_count, 1);
            return [
                'user_id' => $staffUser->id,
                'user_name' => $staffUser->name,
                'user_email' => $staffUser->email,
                'total_requirements' => $total,
                'compliant_count' => $staffUser->compliant_count,
                'expired_count' => $staffUser->expired_count,
                'expiring_soon_count' => $staffUser->expiring_soon_count,
                'not_started_count' => $staffUser->not_started_count,
                'compliance_percent' => $total > 0
                    ? (int) round(($staffUser->compliant_count / $total) * 100)
                    : 0,
            ];
        });

        // Summary counts
        $totalStaff = $activeStaffUserIds->count();

        // Staff with no expired/expiring/not_started statuses = fully compliant
        $fullyCompliant = User::whereIn('id', $activeStaffUserIds)
            ->whereDoesntHave('complianceStatuses', fn ($q) =>
                $q->whereIn('status', ['expired', 'expiring_soon', 'not_started'])
            )
            ->count();

        $hasExpired = User::whereIn('id', $activeStaffUserIds)
            ->whereHas('complianceStatuses', fn ($q) => $q->where('status', 'expired'))
            ->count();

        $hasExpiring = User::whereIn('id', $activeStaffUserIds)
            ->whereHas('complianceStatuses', fn ($q) => $q->where('status', 'expiring_soon'))
            ->count();

        return Inertia::render('hr/compliance/index', [
            'staffStatuses' => $staffPaginated,
            'summary' => [
                'total_staff' => $totalStaff,
                'fully_compliant' => $fullyCompliant,
                'has_expired' => $hasExpired,
                'has_expiring' => $hasExpiring,
            ],
            'requirements' => $requirements,
            'filters' => [
                'q' => $search,
                'status' => $statusFilter,
                'requirement_id' => $requirementId,
            ],
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Staff Detail — per-staff compliance view                           */
    /* ------------------------------------------------------------------ */

    public function staffDetail(Request $request, User $staff)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.compliance.view'), 403);

        $statuses = HrStaffComplianceStatus::where('user_id', $staff->id)
            ->with('requirement:id,code,name,description,category,check_type,hard_stop,validity_months')
            ->orderBy('status')
            ->get();

        $complianceStatuses = $statuses
            ->map(fn (HrStaffComplianceStatus $status) => [
                'id' => $status->id,
                'requirement_id' => $status->requirement_id,
                'requirement_name' => $status->requirement?->name ?? 'Unknown requirement',
                'requirement_type' => $status->requirement?->check_type ?? 'manual',
                'renewal_period_months' => $status->requirement?->validity_months,
                'status' => $status->status,
                'expiry_date' => optional($status->expires_at)->toDateString(),
                'completed_date' => optional($status->valid_from)->toDateString(),
                'evidence_url' => $status->evidence_url ?? null,
                'evidence_notes' => $status->notes ?? null,
                'is_mandatory' => (bool) ($status->requirement?->hard_stop ?? false),
            ])
            ->values();

        $summary = [
            'compliant' => $statuses->where('status', 'compliant')->count(),
            'expiring_soon' => $statuses->where('status', 'expiring_soon')->count(),
            'expired' => $statuses->where('status', 'expired')->count(),
            'not_started' => $statuses->where('status', 'not_started')->count(),
        ];

        $hardStopFailures = $this->complianceMatrixService->getHardStopFailures($staff);
        $softWarnings = $this->complianceMatrixService->getSoftWarnings($staff);

        return Inertia::render('hr/compliance/staff-detail', [
            'staff' => $staff->only(['id', 'name', 'email']),
            'complianceStatuses' => $complianceStatuses,
            'summary' => $summary,
            'statuses' => $statuses,
            'hardStopFailures' => $hardStopFailures,
            'softWarnings' => $softWarnings,
            'can' => [
                'manage' => $user->canDo('hr.compliance.manage'),
            ],
        ]);
    }
}
