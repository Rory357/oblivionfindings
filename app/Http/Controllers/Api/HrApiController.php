<?php

namespace App\Http\Controllers\Api;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Presenters\HrApiPresenter;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrPayrollAccessService;
use App\Domain\Hr\Services\LeaveService;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class HrApiController extends Controller
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly HrCurrentStaffService $currentStaff,
        private readonly HrPayrollAccessService $payrollAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Employees */
    /* ------------------------------------------------------------------ */

    public function employees(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.employees.viewAny'), 403);
        $filters = $request->validate([
            'active' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $employees = HrEmployeeProfile::query()
            ->select($this->employeeColumns())
            ->whereIn('user_id', $this->visibleCurrentStaffUserIds($user))
            ->with(['user:id,name,email', 'primarySite:id,name'])
            ->when(array_key_exists('active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
            ))
            ->orderBy('employee_number')
            ->paginate($this->perPage($filters));
        $this->present($employees, HrApiPresenter::employee(...));

        return response()->json($employees);
    }

    public function employee(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.employees.viewAny'), 403);

        $employee = HrEmployeeProfile::query()
            ->select($this->employeeColumns())
            ->whereIn('user_id', $this->visibleCurrentStaffUserIds($user))
            ->with(['user:id,name,email', 'primarySite:id,name'])
            ->findOrFail($id);

        return response()->json(HrApiPresenter::employee($employee));
    }

    /* ------------------------------------------------------------------ */
    /*  Leave */
    /* ------------------------------------------------------------------ */

    public function leaveRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.leave.viewAny'), 403);
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'declined', 'cancelled'])],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $requests = HrLeaveRequest::query()
            ->select([
                'id', 'user_id', 'leave_type', 'period', 'starts_at', 'ends_at',
                'hours_requested', 'reason', 'supporting_doc_path', 'status',
                'submitted_at', 'approval_due_at', 'reviewed_by', 'reviewed_at',
                'escalation_level', 'escalated_at',
            ])
            ->whereIn('user_id', $this->visibleCurrentStaffUserIds($user))
            ->with(['user:id,name,email', 'reviewer:id,name,email'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->orderByDesc('submitted_at')
            ->paginate($this->perPage($filters));

        // Need-to-know: redact sick / family-violence reason for viewers who aren't
        // the subject or HR (manage), and never expose the private-disk doc path.
        $canSeeSensitive = $user->canDo('hr.leave.manage');
        $requests->getCollection()->transform(function (HrLeaveRequest $r) use ($canSeeSensitive, $user): array {
            $restricted = LeaveService::isSensitiveLeaveType($r->leave_type)
                && ! $canSeeSensitive
                && $r->user_id !== $user->id;

            return HrApiPresenter::leaveRequest(
                $r,
                $restricted,
                ! $restricted && ! empty($r->supporting_doc_path),
            );
        });

        return response()->json($requests);
    }

    public function leaveBalances(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.leave.viewAny'), 403);

        $canViewOthers = $user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage');
        abort_unless((int) $userId === (int) $user->id || $canViewOthers, 403);

        $staffQuery = $this->currentStaffQuery()->whereKey($userId);
        if ((int) $userId !== (int) $user->id) {
            $this->siteAccess->applyStaffScope($staffQuery, $user);
        }
        $isStaffMember = $staffQuery->exists();
        abort_unless($isStaffMember, 404);

        $balances = HrLeaveBalance::query()
            ->select([
                'id', 'user_id', 'leave_type', 'balance_hours', 'accrued_hours',
                'used_hours', 'pending_hours', 'year', 'source', 'last_synced_at',
            ])
            ->where('user_id', $userId)
            ->orderBy('leave_type')
            ->get()
            ->map(HrApiPresenter::leaveBalance(...));

        return response()->json($balances);
    }

    /* ------------------------------------------------------------------ */
    /*  Positions */
    /* ------------------------------------------------------------------ */

    public function positions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.positions.view') || $user?->canDo('hr.employees.viewAny'), 403);
        $filters = $request->validate([
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $positions = HrPosition::query()
            ->select([
                'id', 'title', 'code', 'department', 'team', 'summary',
                'employment_type', 'fte', 'headcount_budget', 'current_headcount',
                'reports_to_position_id', 'is_active',
            ])
            ->when(array_key_exists('active', $filters), fn ($q) => $q->where('is_active', (bool) $filters['active']))
            ->orderBy('title')
            ->paginate($this->perPage($filters));
        $this->present($positions, HrApiPresenter::position(...));

        return response()->json($positions);
    }

    /* ------------------------------------------------------------------ */
    /*  Compliance */
    /* ------------------------------------------------------------------ */

    public function complianceStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.compliance.view'), 403);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:50', 'alpha_dash'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $statuses = HrStaffComplianceStatus::query()
            ->select([
                'id', 'user_id', 'requirement_id', 'status', 'evidence_type',
                'evidence_category', 'valid_from', 'expires_at', 'exempted_at',
                'exempted_until', 'last_checked_at', 'next_check_at',
            ])
            ->whereIn('user_id', $this->visibleCurrentStaffUserIds($user))
            ->with(['user:id,name,email', 'requirement:id,code,name,category,hard_stop'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->paginate($this->perPage($filters));
        $this->present($statuses, HrApiPresenter::complianceStatus(...));

        return response()->json($statuses);
    }

    /* ------------------------------------------------------------------ */
    /*  Time Entries */
    /* ------------------------------------------------------------------ */

    public function timeEntries(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('timesheets.viewAny'), 403);
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $entries = HrTimeEntry::query()
            ->select([
                'id', 'user_id', 'shift_id', 'site_id', 'client_id', 'entry_date',
                'clock_in', 'clock_out', 'break_minutes', 'total_hours', 'entry_type',
                'status', 'pay_type', 'is_sleepover', 'is_on_call', 'is_public_holiday',
                'mileage_km', 'break_compliance_met', 'approved_by', 'approved_at',
            ])
            ->whereIn('user_id', $this->visibleCurrentStaffUserIds($user))
            ->with(['user:id,name,email'])
            ->when($filters['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->where('entry_date', '>=', $from))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->where('entry_date', '<=', $to))
            ->orderByDesc('entry_date')
            ->paginate($this->perPage($filters));
        $this->present($entries, HrApiPresenter::timeEntry(...));

        return response()->json($entries);
    }

    /* ------------------------------------------------------------------ */
    /*  Payroll Runs */
    /* ------------------------------------------------------------------ */

    public function payrollRuns(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.payroll.view'), 403);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:50', 'alpha_dash'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $runs = $this->payrollAccess->visibleRunsQuery($user)
            ->select([
                'id', 'period_start', 'period_end', 'status', 'locked_at', 'locked_by',
                'exported_at', 'exported_by', 'export_format', 'total_hours',
                'total_gross', 'total_staff', 'journal_id', 'gl_posted_at',
                'net_paid_at', 'created_by',
            ])
            ->with(['creator:id,name,email'])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('period_start')
            ->paginate($this->perPage($filters));
        $this->present($runs, HrApiPresenter::payrollRun(...));

        return response()->json($runs);
    }

    private function currentStaffQuery(): Builder
    {
        return $this->currentStaff->currentUsersQuery();
    }

    private function visibleCurrentStaffUserIds(User $viewer): Builder
    {
        $query = $this->currentStaffQuery()->select('users.id');

        return $this->siteAccess->applyStaffScope($query, $viewer);
    }

    /** @return list<string> */
    private function employeeColumns(): array
    {
        return [
            'id', 'user_id', 'employee_number', 'work_email', 'work_phone',
            'position_title', 'position_role', 'employment_type', 'contract_type',
            'start_date', 'end_date', 'is_active', 'primary_site_id',
            'secondary_site_ids', 'position_id', 'manager_user_id', 'department',
            'department_id', 'team', 'preferred_name',
        ];
    }

    /** @param  array<string, mixed>  $filters */
    private function perPage(array $filters): int
    {
        return (int) ($filters['per_page'] ?? 25);
    }

    private function present(LengthAwarePaginator $paginator, callable $presenter): void
    {
        $paginator->setCollection($paginator->getCollection()->map($presenter));
    }
}
