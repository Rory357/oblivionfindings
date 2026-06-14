<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\LeaveReportService;
use App\Domain\Hr\Services\LeaveService;
use App\Http\Requests\Hr\StoreLeaveRequestFormRequest;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LeaveController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly LeaveService $leaveService,
        private readonly HrWebhookService $webhookService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — leave requests list + approval queue                       */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $status = $request->query('status');
        $leaveType = $request->query('leave_type');
        $slaWindow = (string) $request->query('sla', '');
        $search = trim((string) $request->query('q', ''));
        if (! in_array($slaWindow, ['', 'overdue', 'due_24h'], true)) {
            $slaWindow = '';
        }

        // All leave requests for the tenant (managers see all, staff see own)
        $canManage = $user->canDo('hr.leave.manage');
        $canApprove = $user->canDo('hr.leave.approve') || $canManage;
        $canViewAllQueue = $canManage || $canApprove;

        $requests = HrLeaveRequest::forTenant($tenantId)
            ->when(! $canViewAllQueue, fn ($q) => $q->where('user_id', $user->id))
            ->when($status, fn ($q) => match ($status) {
                'pending'  => $q->pending(),
                'approved' => $q->approved(),
                default    => $q->where('status', $status),
            })
            ->when($slaWindow === 'overdue', fn ($q) => $q
                ->where('status', 'pending')
                ->whereNotNull('approval_due_at')
                ->where('approval_due_at', '<', now()))
            ->when($slaWindow === 'due_24h', fn ($q) => $q
                ->where('status', 'pending')
                ->whereNotNull('approval_due_at')
                ->whereBetween('approval_due_at', [now(), now()->copy()->addDay()]))
            ->when($leaveType, fn ($q) => $q->where('leave_type', $leaveType))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
            ))
            ->with([
                'user:id,name,email',
                'reviewer:id,name',
            ])
            ->orderByDesc('submitted_at')
            ->paginate(20)
            ->withQueryString();

        // Transform paginated data to match frontend LeaveRequest shape
        $requests->through(fn ($req) => [
            'id'          => $req->id,
            'staff_name'  => $req->user?->name ?? 'Unknown',
            'staff_id'    => $req->user_id,
            'leave_type'  => $req->leave_type,
            'start_date'  => $req->starts_at?->toDateString(),
            'end_date'    => $req->ends_at?->toDateString(),
            'hours'       => (float) $req->hours_requested,
            'status'      => $req->status,
            'reason'      => $req->reason,
            'reviewed_by' => $req->reviewer?->name,
            'approval_due_at' => $req->approval_due_at?->toDateTimeString(),
            'is_overdue' => $req->status === 'pending' && $req->approval_due_at?->isPast(),
            'due_within_24h' => $req->status === 'pending' && $req->approval_due_at?->between(now(), now()->copy()->addDay()),
            ]);

        $sla = $this->leaveService->approvalSlaSummary(
            tenantId: $tenantId,
            viewerUserId: $user->id,
            canManage: $canViewAllQueue,
        );

        $pendingAging = HrLeaveRequest::forTenant($tenantId)
            ->where('status', 'pending')
            ->when(! $canViewAllQueue, fn ($query) => $query->where('user_id', $user->id))
            ->with('user:id,name')
            ->orderBy('submitted_at')
            ->limit(10)
            ->get()
            ->map(fn (HrLeaveRequest $req) => [
                'id' => $req->id,
                'staff_name' => $req->user?->name ?? 'Unknown',
                'leave_type' => $req->leave_type,
                'submitted_at' => optional($req->submitted_at)->toDateTimeString(),
                'approval_due_at' => optional($req->approval_due_at)->toDateTimeString(),
                'hours_waiting' => $req->submitted_at ? round($req->submitted_at->diffInMinutes(now()) / 60, 1) : 0,
            ])
            ->values();

        // --- Dashboard Analytics ---

        // Monthly leave trend (last 6 months)
        $monthlyTrend = HrLeaveRequest::forTenant($tenantId)
            ->where('submitted_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("DATE_FORMAT(submitted_at, '%Y-%m') as month")
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved")
            ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
            ->selectRaw("SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) as declined")
            ->selectRaw('SUM(hours_requested) as total_hours')
            ->groupByRaw("DATE_FORMAT(submitted_at, '%Y-%m')")
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => [
                'month' => Carbon::parse($row->month . '-01')->format('M'),
                'approved' => (int) $row->approved,
                'pending' => (int) $row->pending,
                'declined' => (int) $row->declined,
                'total_hours' => round((float) $row->total_hours, 1),
            ]);

        // Leave type breakdown (current year)
        $typeBreakdown = HrLeaveRequest::forTenant($tenantId)
            ->whereIn('status', ['approved', 'pending'])
            ->whereYear('submitted_at', now()->year)
            ->selectRaw('leave_type, COUNT(*) as count')
            ->groupBy('leave_type')
            ->get()
            ->map(fn ($row) => [
                'type' => ucwords(str_replace('_', ' ', $row->leave_type)),
                'value' => (int) $row->count,
            ]);

        // Top 5 absentees (sick leave this year)
        $topAbsentees = HrLeaveRequest::forTenant($tenantId)
            ->where('status', 'approved')
            ->where('leave_type', 'sick')
            ->whereYear('starts_at', now()->year)
            ->with('user:id,name')
            ->selectRaw('user_id, SUM(hours_requested) as total_hours, COUNT(*) as occurrences')
            ->groupBy('user_id')
            ->orderByDesc('total_hours')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'name' => $row->user?->name ?? 'Unknown',
                'hours' => round((float) $row->total_hours, 1),
                'occurrences' => (int) $row->occurrences,
            ]);

        // Staff on leave today
        $onLeaveToday = HrLeaveRequest::forTenant($tenantId)
            ->where('status', 'approved')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->with('user:id,name')
            ->get()
            ->map(fn ($req) => [
                'id' => $req->id,
                'name' => $req->user?->name ?? 'Unknown',
                'leave_type' => $req->leave_type,
                'end_date' => $req->ends_at?->toDateString(),
            ]);

        // Upcoming leave this week
        $upcomingLeaveThisWeek = HrLeaveRequest::forTenant($tenantId)
            ->where('status', 'approved')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->endOfWeek())
            ->with('user:id,name')
            ->orderBy('starts_at')
            ->limit(10)
            ->get()
            ->map(fn ($req) => [
                'id' => $req->id,
                'name' => $req->user?->name ?? 'Unknown',
                'leave_type' => $req->leave_type,
                'start_date' => $req->starts_at?->toDateString(),
            ]);

        // Leave utilisation overview
        $totalActiveStaff = HrEmployeeProfile::where('tenant_id', $tenantId)->where('is_active', true)->count();

        // Absence rate (last 30 days)
        $sickDaysLast30 = HrLeaveRequest::forTenant($tenantId)
            ->where('status', 'approved')
            ->where('leave_type', 'sick')
            ->where('starts_at', '>=', now()->subDays(30))
            ->sum('hours_requested');
        $possibleHours = max(1, $totalActiveStaff * 160); // ~20 working days × 8h
        $absenceRate = round(((float) $sickDaysLast30 / $possibleHours) * 100, 1);

        // Roster impact: shifts affected by pending leave
        $pendingLeaveUserIds = HrLeaveRequest::forTenant($tenantId)
            ->where('status', 'pending')
            ->pluck('user_id')
            ->unique();
        $rosterImpact = 0;
        if ($pendingLeaveUserIds->isNotEmpty()) {
            $rosterImpact = Shift::whereIn('user_id', $pendingLeaveUserIds)
                ->whereIn('status', ['scheduled', 'draft'])
                ->where('starts_at', '>=', now())
                ->count();
        }

        return Inertia::render('hr/leave/index', [
            'requests' => $requests,
            'filters' => [
                'status' => $status,
                'leave_type' => $leaveType,
                'sla' => $slaWindow !== '' ? $slaWindow : null,
            ],
            'sla' => $sla,
            'pendingAging' => $pendingAging,
            'dashboardData' => [
                'monthlyTrend' => $monthlyTrend,
                'typeBreakdown' => $typeBreakdown,
                'topAbsentees' => $topAbsentees,
                'onLeaveToday' => $onLeaveToday,
                'upcomingLeaveThisWeek' => $upcomingLeaveThisWeek,
                'absenceRate' => $absenceRate,
                'totalActiveStaff' => $totalActiveStaff,
                'rosterImpact' => $rosterImpact,
            ],
            'staff' => $this->leaveFormStaff($tenantId),
            'leaveTypes' => $this->leaveTypeOptions(),
            'can' => [
                'approve' => $canApprove,
                'manage'  => $canManage,
                'create'  => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /** Staff selectable in the leave-request form (tenant-scoped). */
    private function leaveFormStaff(int $tenantId): Collection
    {
        $staffIds = $this->hrStaffUserIdsForTenant($tenantId);

        return User::staff()
            ->when($staffIds !== [], fn ($query) => $query->whereIn('id', $staffIds))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    /** Leave-type options as {value,label} for the request form. */
    private function leaveTypeOptions(): array
    {
        return array_map(fn ($type) => [
            'value' => $type,
            'label' => ucwords(str_replace('_', ' ', $type)),
        ], LeaveService::LEAVE_TYPES);
    }

    /* ------------------------------------------------------------------ */
    /*  Balances — overview of leave balances                              */
    /* ------------------------------------------------------------------ */

    public function balances(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $canManage = $user->canDo('hr.leave.manage');
        $year = (int) $request->query('year', now()->year);
        $search = trim((string) $request->query('q', ''));

        $balances = HrLeaveBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
            ))
            ->with('user:id,name,email')
            ->orderBy('leave_type')
            ->paginate(50)
            ->withQueryString();

        // The page reads entitlement/taken/remaining; the model stores
        // balance/used/pending. Map so the columns aren't blank.
        $balances->through(fn (HrLeaveBalance $b) => [
            'id' => $b->id,
            'user' => [
                'id' => $b->user?->id,
                'name' => $b->user?->name ?? 'Unknown',
                'email' => $b->user?->email,
            ],
            'leave_type' => $b->leave_type,
            'year' => $b->year,
            'entitlement_hours' => (float) $b->balance_hours,
            'taken_hours' => (float) $b->used_hours,
            'pending_hours' => (float) $b->pending_hours,
            'remaining_hours' => (float) ($b->balance_hours - $b->used_hours - $b->pending_hours),
        ]);

        return Inertia::render('hr/leave/balances', [
            'balances' => $balances,
            'year' => $year,
            'leaveTypes' => LeaveService::LEAVE_TYPES,
            'filters' => [
                'year' => $year,
                'q' => $search,
            ],
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create — show form to create leave request                         */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        // The page-based form was replaced by the LeaveRequestDialog on the hub.
        return redirect()->route('hr.leave.index');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — view single leave request                                   */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrLeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $leaveRequest->tenant_id);

        $leaveRequest->load([
            'user:id,name,email',
            'reviewer:id,name',
        ]);

        return Inertia::render('hr/leave/show', [
            'request' => [
                'id' => $leaveRequest->id,
                'staff_name' => $leaveRequest->user?->name ?? 'Unknown',
                'staff_id' => $leaveRequest->user_id,
                'leave_type' => $leaveRequest->leave_type,
                'start_date' => $leaveRequest->starts_at?->toDateString(),
                'end_date' => $leaveRequest->ends_at?->toDateString(),
                'hours' => (float) $leaveRequest->hours_requested,
                'status' => $leaveRequest->status,
                'reason' => $leaveRequest->reason,
                'reviewed_by' => $leaveRequest->reviewer?->name,
                'reviewed_at' => $leaveRequest->reviewed_at?->toDateTimeString(),
                'review_notes' => $leaveRequest->review_notes,
                'submitted_at' => $leaveRequest->submitted_at?->toDateTimeString(),
                'supporting_doc_path' => $leaveRequest->supporting_doc_path,
            ],
            'can' => [
                'approve' => $user->canDo('hr.leave.approve') && $leaveRequest->status === 'pending',
                'manage' => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store — submit a leave request                                     */
    /* ------------------------------------------------------------------ */

    public function store(StoreLeaveRequestFormRequest $request)
    {
        $user = $request->user();
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validated();
        $data = $validated;

        if ($request->hasFile('supporting_doc')) {
            $data['supporting_doc_path'] = $request->file('supporting_doc')
                ->store("leave/{$user->id}", 'private');
        }

        $requestUser = $user;
        if (! empty($validated['user_id']) && (int) $validated['user_id'] !== (int) $user->id) {
            abort_unless($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage'), 403);
            $requestUser = User::query()->findOrFail((int) $validated['user_id']);
            $profileTenantId = HrEmployeeProfile::query()
                ->where('user_id', $requestUser->id)
                ->value('tenant_id');
            if (is_numeric($profileTenantId) && (int) $profileTenantId !== $tenantId) {
                abort(404);
            }
        }
        $data['created_by'] = $user->id;
        $data['tenant_id'] = $tenantId;

        try {
            $leaveRequest = $this->leaveService->submitRequest($requestUser, $data);
            $this->webhookService->publish($leaveRequest->tenant_id, 'leave.request.submitted', [
                'leave_request_id' => $leaveRequest->id,
                'user_id' => $leaveRequest->user_id,
                'leave_type' => $leaveRequest->leave_type,
                'status' => $leaveRequest->status,
                'starts_at' => optional($leaveRequest->starts_at)->toDateString(),
                'ends_at' => optional($leaveRequest->ends_at)->toDateString(),
                'hours_requested' => (float) $leaveRequest->hours_requested,
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['leave_request' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Leave request submitted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve                                                            */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, HrLeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage')), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $leaveRequest->tenant_id);

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $approved = $this->leaveService->approveRequest(
                $leaveRequest,
                $user,
                $validated['review_notes'] ?? null,
            );

            $this->webhookService->publish($approved->tenant_id, 'leave.request.approved', [
                'leave_request_id' => $approved->id,
                'user_id' => $approved->user_id,
                'reviewed_by' => $approved->reviewed_by,
                'leave_type' => $approved->leave_type,
                'starts_at' => optional($approved->starts_at)->toDateString(),
                'ends_at' => optional($approved->ends_at)->toDateString(),
                'hours_requested' => (float) $approved->hours_requested,
            ]);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Leave request approved.');
    }

    /* ------------------------------------------------------------------ */
    /*  Decline                                                            */
    /* ------------------------------------------------------------------ */

    public function decline(Request $request, HrLeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage')), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $leaveRequest->tenant_id);

        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $declined = $this->leaveService->declineRequest(
                $leaveRequest,
                $user,
                $validated['review_notes'],
            );

            $this->webhookService->publish($declined->tenant_id, 'leave.request.declined', [
                'leave_request_id' => $declined->id,
                'user_id' => $declined->user_id,
                'reviewed_by' => $declined->reviewed_by,
                'leave_type' => $declined->leave_type,
                'review_notes' => $declined->review_notes,
            ]);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Leave request declined.');
    }

    public function bulkApprove(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage')), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'request_ids' => ['required', 'array', 'min:1', 'max:200'],
            'request_ids.*' => ['integer', 'distinct'],
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $ids = collect($validated['request_ids'])->map(fn ($id) => (int) $id)->values();
        $requests = $this->loadPendingRequestsForBulk($ids, $tenantId);

        if ($requests->count() !== $ids->count()) {
            return redirect()->back()->withErrors([
                'request_ids' => 'Some selected requests are no longer pending or not accessible.',
            ]);
        }

        $approved = 0;
        foreach ($requests as $leaveRequest) {
            try {
                $approvedRequest = $this->leaveService->approveRequest($leaveRequest, $user, $validated['review_notes'] ?? null);
                $this->webhookService->publish($approvedRequest->tenant_id, 'leave.request.approved', [
                    'leave_request_id' => $approvedRequest->id,
                    'user_id' => $approvedRequest->user_id,
                    'reviewed_by' => $approvedRequest->reviewed_by,
                    'leave_type' => $approvedRequest->leave_type,
                    'hours_requested' => (float) $approvedRequest->hours_requested,
                    'bulk' => true,
                ]);
                $approved++;
            } catch (\LogicException) {
                // ignore race-condition rows and continue bulk processing
            }
        }

        return redirect()->back()->with('success', "{$approved} leave request(s) approved.");
    }

    public function bulkDecline(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage')), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'request_ids' => ['required', 'array', 'min:1', 'max:200'],
            'request_ids.*' => ['integer', 'distinct'],
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        $ids = collect($validated['request_ids'])->map(fn ($id) => (int) $id)->values();
        $requests = $this->loadPendingRequestsForBulk($ids, $tenantId);

        if ($requests->count() !== $ids->count()) {
            return redirect()->back()->withErrors([
                'request_ids' => 'Some selected requests are no longer pending or not accessible.',
            ]);
        }

        $declined = 0;
        foreach ($requests as $leaveRequest) {
            try {
                $declinedRequest = $this->leaveService->declineRequest($leaveRequest, $user, $validated['review_notes']);
                $this->webhookService->publish($declinedRequest->tenant_id, 'leave.request.declined', [
                    'leave_request_id' => $declinedRequest->id,
                    'user_id' => $declinedRequest->user_id,
                    'reviewed_by' => $declinedRequest->reviewed_by,
                    'leave_type' => $declinedRequest->leave_type,
                    'review_notes' => $declinedRequest->review_notes,
                    'bulk' => true,
                ]);
                $declined++;
            } catch (\LogicException) {
                // ignore race-condition rows and continue bulk processing
            }
        }

        return redirect()->back()->with('success', "{$declined} leave request(s) declined.");
    }

    public function setSlaDue(Request $request, HrLeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage')), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $leaveRequest->tenant_id);

        if ($leaveRequest->status !== 'pending') {
            return redirect()->back()->withErrors([
                'leave_request' => 'Only pending requests can have SLA due updated.',
            ]);
        }

        $validated = $request->validate([
            'hours' => ['required', 'integer', 'min:1', 'max:168'],
        ]);

        $leaveRequest->update([
            'approval_due_at' => now()->addHours((int) $validated['hours']),
            'escalated_at' => now(),
        ]);

        $this->webhookService->publish($leaveRequest->tenant_id, 'leave.request.escalated', [
            'leave_request_id' => $leaveRequest->id,
            'user_id' => $leaveRequest->user_id,
            'escalation_level' => (int) ($leaveRequest->escalation_level ?? 1),
            'approval_due_at' => optional($leaveRequest->approval_due_at)->toDateTimeString(),
            'manual' => true,
        ]);

        return redirect()->back()->with('success', 'SLA due date updated.');
    }

    public function escalateNow(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage')), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $escalated = $this->leaveService->escalatePendingApprovals($tenantId);

        if ($escalated > 0) {
            $this->webhookService->publish($tenantId, 'leave.request.escalated', [
                'escalated_count' => $escalated,
                'manual' => true,
            ]);
        }

        return redirect()->back()->with('success', "{$escalated} request(s) escalated.");
    }

    /**
     * @param Collection<int, int> $ids
     * @return Collection<int, HrLeaveRequest>
     */
    private function loadPendingRequestsForBulk(Collection $ids, int $tenantId): Collection
    {
        return HrLeaveRequest::query()
            ->whereIn('id', $ids->all())
            ->where('status', 'pending')
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get();
    }
}
