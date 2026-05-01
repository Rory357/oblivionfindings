<?php

namespace App\Http\Controllers\Api;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPosition;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrApiController extends Controller
{
    /* ------------------------------------------------------------------ */
    /*  Employees */
    /* ------------------------------------------------------------------ */

    public function employees(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.employees.viewAny'), 403);

        $employees = HrEmployeeProfile::forTenant($user->tenant_id)
            ->with(['user:id,name,email', 'primarySite:id,name'])
            ->when($request->query('active'), fn ($q) => $q->where('is_active', true))
            ->when($request->query('q'), fn ($q, $search) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
            ))
            ->orderBy('employee_number')
            ->paginate($request->integer('per_page', 25));

        return response()->json($employees);
    }

    public function employee(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.employees.viewAny'), 403);

        $employee = HrEmployeeProfile::forTenant($user->tenant_id)
            ->with(['user:id,name,email', 'primarySite:id,name'])
            ->findOrFail($id);

        return response()->json($employee);
    }

    /* ------------------------------------------------------------------ */
    /*  Leave */
    /* ------------------------------------------------------------------ */

    public function leaveRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.leave.viewAny'), 403);

        $requests = HrLeaveRequest::forTenant($user->tenant_id)
            ->with(['user:id,name,email', 'reviewer:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->orderByDesc('submitted_at')
            ->paginate($request->integer('per_page', 25));

        return response()->json($requests);
    }

    public function leaveBalances(Request $request, int $userId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.leave.viewAny'), 403);

        $balances = HrLeaveBalance::forTenant($user->tenant_id)
            ->where('user_id', $userId)
            ->orderBy('leave_type')
            ->get();

        return response()->json($balances);
    }

    /* ------------------------------------------------------------------ */
    /*  Positions */
    /* ------------------------------------------------------------------ */

    public function positions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.employees.viewAny'), 403);

        $positions = HrPosition::forTenant($user->tenant_id)
            ->when($request->query('active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('title')
            ->paginate($request->integer('per_page', 25));

        return response()->json($positions);
    }

    /* ------------------------------------------------------------------ */
    /*  Compliance */
    /* ------------------------------------------------------------------ */

    public function complianceStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.compliance.view'), 403);

        $statuses = HrStaffComplianceStatus::forTenant($user->tenant_id)
            ->with(['user:id,name,email'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->paginate($request->integer('per_page', 25));

        return response()->json($statuses);
    }

    /* ------------------------------------------------------------------ */
    /*  Time Entries */
    /* ------------------------------------------------------------------ */

    public function timeEntries(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('timesheets.viewAny'), 403);

        $entries = HrTimeEntry::forTenant($user->tenant_id)
            ->with(['user:id,name,email'])
            ->when($request->query('user_id'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($request->query('date_from'), fn ($q, $from) => $q->where('date', '>=', $from))
            ->when($request->query('date_to'), fn ($q, $to) => $q->where('date', '<=', $to))
            ->orderByDesc('date')
            ->paginate($request->integer('per_page', 25));

        return response()->json($entries);
    }

    /* ------------------------------------------------------------------ */
    /*  Payroll Runs */
    /* ------------------------------------------------------------------ */

    public function payrollRuns(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user?->canDo('hr.payroll.view'), 403);

        $runs = HrPayrollRun::forTenant($user->tenant_id)
            ->with(['creator:id,name'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('period_start')
            ->paginate($request->integer('per_page', 25));

        return response()->json($runs);
    }
}
