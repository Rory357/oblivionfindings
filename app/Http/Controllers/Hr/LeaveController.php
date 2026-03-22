<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrPublicHoliday;
use App\Domain\Hr\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LeaveController extends Controller
{
    public function __construct(
        private readonly LeaveService $leaveService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — leave requests list + approval queue                       */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = null;
        $status = $request->query('status');
        $leaveType = $request->query('leave_type');
        $search = trim((string) $request->query('q', ''));

        // All leave requests for the tenant (managers see all, staff see own)
        $canManage = $user->canDo('hr.leave.manage');

        $requests = HrLeaveRequest::forTenant($tenantId)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when($status, fn ($q) => match ($status) {
                'pending'  => $q->pending(),
                'approved' => $q->approved(),
                default    => $q->where('status', $status),
            })
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
        ]);

        return Inertia::render('hr/leave/index', [
            'requests' => $requests,
            'filters' => [
                'status' => $status,
                'leave_type' => $leaveType,
            ],
            'can' => [
                'approve' => $canManage,
                'manage'  => $canManage,
                'create'  => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Balances — overview of leave balances                              */
    /* ------------------------------------------------------------------ */

    public function balances(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = null;
        $canManage = $user->canDo('hr.leave.manage');
        $year = (int) $request->query('year', now()->year);
        $search = trim((string) $request->query('q', ''));

        $balances = HrLeaveBalance::where('year', $year)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) =>
                $u->where('name', 'like', "%{$search}%")
            ))
            ->with('user:id,name,email')
            ->orderBy('leave_type')
            ->paginate(50)
            ->withQueryString();

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

        $staff = \App\Models\User::orderBy('name')
            ->get(['id', 'name', 'email']);

        return Inertia::render('hr/leave/create', [
            'staff' => $staff,
            'leaveTypes' => array_map(fn ($type) => [
                'value' => $type,
                'label' => ucwords(str_replace('_', ' ', $type)),
            ], LeaveService::LEAVE_TYPES),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Show — view single leave request                                   */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrLeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

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

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $validated = $request->validate([
            'leave_type'      => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'starts_at'       => ['required', 'date', 'after_or_equal:today'],
            'ends_at'         => ['required', 'date', 'after_or_equal:starts_at'],
            'hours_requested' => ['required', 'numeric', 'min:0.5', 'max:999'],
            'reason'          => ['nullable', 'string', 'max:2000'],
            'supporting_doc'  => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);

        $data = $validated;

        if ($request->hasFile('supporting_doc')) {
            $data['supporting_doc_path'] = $request->file('supporting_doc')
                ->store("leave/{$user->id}", 'private');
        }

        try {
            $this->leaveService->submitRequest($user, $data);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Leave request submitted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve                                                            */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, HrLeaveRequest $leaveRequest)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $validated = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->leaveService->approveRequest(
                $leaveRequest,
                $user,
                $validated['review_notes'] ?? null,
            );
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
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->leaveService->declineRequest(
                $leaveRequest,
                $user,
                $validated['review_notes'],
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Leave request declined.');
    }

    /* ------------------------------------------------------------------ */
    /*  Holidays — list public holidays                                    */
    /* ------------------------------------------------------------------ */

    public function holidays(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $year = (int) $request->query('year', now()->year);

        $holidays = HrPublicHoliday::forYear($year)
            ->orderBy('date')
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'date' => $h->date->toDateString(),
                'region' => $h->region,
                'is_national' => $h->is_national,
                'year' => $h->year,
            ]);

        return Inertia::render('hr/leave/holidays', [
            'holidays' => $holidays,
            'year' => $year,
            'can' => [
                'manage' => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Store Holiday — add a custom public holiday                        */
    /* ------------------------------------------------------------------ */

    public function storeHoliday(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'region' => ['nullable', 'string', 'max:100'],
            'is_national' => ['boolean'],
        ]);

        HrPublicHoliday::create([
            'tenant_id' => null,
            'name' => $validated['name'],
            'date' => $validated['date'],
            'region' => $validated['region'] ?? null,
            'is_national' => $validated['is_national'] ?? false,
            'year' => (int) date('Y', strtotime($validated['date'])),
        ]);

        return redirect()->back()->with('success', 'Public holiday added.');
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy Holiday — remove a public holiday                          */
    /* ------------------------------------------------------------------ */

    public function destroyHoliday(Request $request, HrPublicHoliday $holiday)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        $holiday->delete();

        return redirect()->back()->with('success', 'Public holiday removed.');
    }
}
