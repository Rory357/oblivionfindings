<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\HrWebhookService;
use App\Domain\Hr\Services\LeaveService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\StoreLeaveRequestFormRequest;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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
    /*  Index — leave requests list + approval queue */
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
                'pending' => $q->pending(),
                'approved' => $q->approved(),
                default => $q->where('status', $status),
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
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
            ))
            ->with([
                'user:id,name,email',
                'reviewer:id,name',
            ])
            ->orderByDesc('submitted_at')
            ->paginate(20)
            ->withQueryString();

        // Cross-page, SLA-ordered segmented approvals inbox (handover §3.1).
        $inboxRaw = $this->leaveService->pendingInbox($tenantId, $user, $canViewAllQueue);

        // Batch-annotate the page rows + every inbox item with roster-conflict / balance
        // impact in one pass (handover §3.2) — no N+1.
        $pageModels = collect($requests->items());
        $inboxModels = collect($inboxRaw)->flatMap(fn ($seg) => $seg['items']);
        $context = $this->leaveService->annotateRequestsContext(
            $pageModels->merge($inboxModels)->unique('id')->values()
        );

        // Transform paginated data to match frontend LeaveRequest shape
        $requests->through(fn ($req) => $this->transformLeaveRow($req, $context[$req->id] ?? []));

        $inbox = collect($inboxRaw)->map(fn ($seg) => [
            'count' => $seg['count'],
            'items' => $seg['items']->map(fn ($req) => $this->transformLeaveRow($req, $context[$req->id] ?? []))->values(),
        ])->all();

        $sla = $this->leaveService->approvalSlaSummary(
            tenantId: $tenantId,
            viewerUserId: $user->id,
            canManage: $canViewAllQueue,
        );

        // Active hub pane. Overview / Approvals / Calendar render in-page; Balances and
        // Reports are their own routes (re-chromed with the same hub tab strip).
        $activeTab = (string) $request->query('tab', 'overview');
        if (! in_array($activeTab, ['overview', 'approvals', 'calendar'], true)) {
            $activeTab = 'overview';
        }

        // Calendar feed is built only when the Calendar tab is active (lazy, per §6.3).
        $calendar = $activeTab === 'calendar'
            ? $this->leaveService->calendarFeed(
                $tenantId,
                (string) $request->query('month', now()->format('Y-m')),
                ['site_id' => $request->query('site_id')],
            )
            : null;

        return Inertia::render('hr/leave/index', [
            'requests' => $requests,
            'filters' => [
                'status' => $status,
                'leave_type' => $leaveType,
                'sla' => $slaWindow !== '' ? $slaWindow : null,
                'q' => $search !== '' ? $search : null,
            ],
            'sla' => $sla,
            'hero' => $this->leaveService->hubHeroData($tenantId, $user, $canViewAllQueue),
            'tab' => $activeTab,
            'approvalInbox' => $inbox,
            'calendar' => $calendar,
            'staff' => $this->leaveFormStaff($tenantId),
            'leaveTypes' => $this->leaveTypeOptions(),
            'can' => [
                'approve' => $canApprove,
                'manage' => $canManage,
                'create' => $user->canDo('hr.leave.manage'),
            ],
        ]);
    }

    /**
     * Shared row shape for the requests list AND the approvals inbox segments,
     * enriched with the per-request roster-conflict / balance-impact context.
     *
     * @param  array{rosterConflict?: array, balanceImpact?: array|null}  $ctx
     */
    private function transformLeaveRow(HrLeaveRequest $req, array $ctx): array
    {
        $isPending = $req->status === 'pending';

        return [
            'id' => $req->id,
            'staff_name' => $req->user?->name ?? 'Unknown',
            'staff_id' => $req->user_id,
            'leave_type' => $req->leave_type,
            'period' => $req->period ?: 'full_day',
            'start_date' => $req->starts_at?->toDateString(),
            'end_date' => $req->ends_at?->toDateString(),
            'hours' => (float) $req->hours_requested,
            'status' => $req->status,
            'reason' => $req->reason,
            'has_doc' => ! empty($req->supporting_doc_path),
            'reviewed_by' => $req->reviewer?->name,
            'reviewed_at' => $req->reviewed_at?->toDateTimeString(),
            'submitted_at' => $req->submitted_at?->toDateTimeString(),
            'hours_waiting' => $req->submitted_at ? round($req->submitted_at->diffInMinutes(now()) / 60, 1) : 0,
            'approval_due_at' => $req->approval_due_at?->toDateTimeString(),
            'is_overdue' => $isPending && (bool) $req->approval_due_at?->isPast(),
            'due_within_24h' => $isPending && (bool) $req->approval_due_at?->between(now(), now()->copy()->addDay()),
            'escalation_level' => (int) ($req->escalation_level ?? 1),
            'escalated' => (int) ($req->escalation_level ?? 1) > 1,
            'escalated_from' => $req->escalatedTo?->name,
            'roster_conflict' => $ctx['rosterConflict'] ?? ['has_conflict' => false, 'count' => 0, 'shifts' => []],
            'balance_impact' => $ctx['balanceImpact'] ?? null,
        ];
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
    /*  Balances — overview of leave balances */
    /* ------------------------------------------------------------------ */

    public function balances(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $canManage = $user->canDo('hr.leave.manage');
        $canApprove = $user->canDo('hr.leave.approve') || $canManage;
        $year = (int) $request->query('year', now()->year);
        $search = trim((string) $request->query('q', ''));

        // Pivot to one row per staff member (Annual · Sick · Alt/lieu · Pending)
        // — the design's balances grid. Hours-based; a row opens the combined
        // immutable ledger.
        $balances = HrLeaveBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->when(! $canManage, fn ($q) => $q->where('user_id', $user->id))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
            ))
            ->with('user:id,name,email')
            ->get()
            ->groupBy('user_id')
            ->map(function ($group) {
                $member = $group->first()->user;
                $forType = function (string $type) use ($group) {
                    $row = $group->firstWhere('leave_type', $type);

                    return [
                        'remaining' => $row ? round((float) ($row->balance_hours - $row->used_hours - $row->pending_hours), 1) : 0.0,
                        'entitlement' => $row ? round((float) $row->balance_hours, 1) : 0.0,
                    ];
                };
                $annual = $forType('annual');

                return [
                    'user_id' => $member?->id,
                    'name' => $member?->name ?? 'Unknown',
                    'email' => $member?->email,
                    'annual' => $annual,
                    'sick' => $forType('sick'),
                    'alternative' => $forType('alternative'),
                    'pending' => round((float) $group->sum('pending_hours'), 1),
                    'low' => $annual['entitlement'] > 0 && $annual['remaining'] <= $annual['entitlement'] * 0.10,
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('hr/leave/balances', [
            'balances' => $balances,
            'year' => $year,
            'hero' => $this->leaveService->hubHeroData($tenantId, $user, $canApprove),
            'leaveTypes' => LeaveService::LEAVE_TYPES,
            'filters' => [
                'year' => $year,
                'q' => $search,
            ],
            'can' => [
                'manage' => $canManage,
                'approve' => $canApprove,
                'create' => $canManage,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Preview — read-only computed hours/balance/conflict/approver */
    /* ------------------------------------------------------------------ */

    public function previewLeave(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.viewAny'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'leave_type' => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'period' => ['nullable', Rule::in(['full_day', 'half_day_am', 'half_day_pm'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
        ]);

        $target = $user;
        if (! empty($validated['user_id']) && (int) $validated['user_id'] !== (int) $user->id) {
            abort_unless($user->canDo('hr.leave.approve') || $user->canDo('hr.leave.manage'), 403);
            $target = User::query()->findOrFail((int) $validated['user_id']);
        }

        try {
            $preview = $this->leaveService->previewRequest($target, array_merge($validated, ['tenant_id' => $tenantId]));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    /* ------------------------------------------------------------------ */
    /*  Balance adjustment + immutable ledger read */
    /* ------------------------------------------------------------------ */

    public function adjustBalance(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'leave_type' => ['required', 'string', Rule::in(LeaveService::LEAVE_TYPES)],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'mode' => ['required', Rule::in(['credit', 'debit', 'set_opening'])],
            'hours' => ['required', 'numeric', 'min:0', 'max:9999'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $target = User::query()->findOrFail((int) $validated['user_id']);
        $profileTenantId = HrEmployeeProfile::query()->where('user_id', $target->id)->value('tenant_id');
        if (is_numeric($profileTenantId) && (int) $profileTenantId !== $tenantId) {
            abort(404);
        }

        try {
            $this->leaveService->adjustBalance(
                target: $target,
                leaveType: $validated['leave_type'],
                year: (int) ($validated['year'] ?? now()->year),
                mode: $validated['mode'],
                hours: (float) $validated['hours'],
                reason: $validated['reason'] ?? null,
                actor: $user,
                tenantId: $tenantId,
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withErrors(['adjust' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'Balance adjusted — ledger entry recorded.');
    }

    public function ledger(Request $request, User $user)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->canDo('hr.leave.viewAny'), 403);
        if (! $actor->canDo('hr.leave.manage') && $user->id !== $actor->id) {
            abort(403);
        }

        $year = (int) $request->query('year', now()->year);
        $leaveType = $request->query('leave_type');
        $rows = $this->leaveService->balanceLedger($user->id, $leaveType, $year);

        $actorNames = User::query()
            ->whereIn('id', $rows->pluck('created_by')->filter()->unique()->all())
            ->pluck('name', 'id');

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'year' => $year,
            'entries' => $rows->map(fn ($e) => [
                'id' => $e->id,
                'leave_type' => $e->leave_type,
                'entry_type' => $e->entry_type,
                'hours_delta' => (float) $e->hours_delta,
                'balance_after' => (float) $e->balance_hours_after,
                'used_after' => (float) $e->used_hours_after,
                'pending_after' => (float) $e->pending_hours_after,
                'notes' => $e->notes,
                'created_by' => $actorNames[$e->created_by] ?? null,
                'created_at' => $e->created_at?->toDateTimeString(),
            ])->values(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Export — requests / balances (CSV · Excel-openable · PDF) */
    /* ------------------------------------------------------------------ */

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);

        $rows = HrLeaveRequest::forTenant($tenantId)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('leave_type'), fn ($q, $t) => $q->where('leave_type', $t))
            ->with(['user:id,name', 'reviewer:id,name'])
            ->orderByDesc('submitted_at')
            ->get();

        $headers = ['Staff', 'Leave type', 'Period', 'Start', 'End', 'Hours', 'Status', 'Submitted', 'Reviewed by', 'Reason'];
        $records = $rows->map(fn (HrLeaveRequest $r) => [
            $r->user?->name ?? 'Unknown',
            ucwords(str_replace('_', ' ', (string) $r->leave_type)),
            str_replace('_', ' ', (string) ($r->period ?: 'full_day')),
            $r->starts_at?->toDateString(),
            $r->ends_at?->toDateString(),
            (float) $r->hours_requested,
            $r->status,
            $r->submitted_at?->toDateString(),
            $r->reviewer?->name,
            $r->reason,
        ])->all();

        return $this->streamLeaveExport(
            (string) $request->query('format', 'csv'),
            'leave-requests-'.now()->format('Y-m-d'),
            'Leave requests',
            $headers,
            $records,
        );
    }

    public function exportBalances(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $year = (int) $request->query('year', now()->year);

        $rows = HrLeaveBalance::query()
            ->where('tenant_id', $tenantId)
            ->where('year', $year)
            ->with('user:id,name')
            ->orderBy('leave_type')
            ->get();

        $headers = ['Staff', 'Leave type', 'Year', 'Entitlement', 'Taken', 'Pending', 'Remaining'];
        $records = $rows->map(fn (HrLeaveBalance $b) => [
            $b->user?->name ?? 'Unknown',
            ucwords(str_replace('_', ' ', (string) $b->leave_type)),
            $b->year,
            (float) $b->balance_hours,
            (float) $b->used_hours,
            (float) $b->pending_hours,
            round((float) $b->balance_hours - (float) $b->used_hours - (float) $b->pending_hours, 2),
        ])->all();

        return $this->streamLeaveExport(
            (string) $request->query('format', 'csv'),
            'leave-balances-'.$year,
            "Leave balances {$year}",
            $headers,
            $records,
        );
    }

    /**
     * CSV (also opens in Excel) or PDF stream. Free-text cells are neutralised against
     * spreadsheet formula injection.
     *
     * @param  array<int, array<int, mixed>>  $records
     */
    private function streamLeaveExport(string $format, string $filename, string $title, array $headers, array $records)
    {
        if (strtolower($format) === 'pdf') {
            $head = collect($headers)->map(fn ($h) => '<th style="text-align:left;border:1px solid #ccc;padding:4px;background:#f3f3f3">'.e($h).'</th>')->implode('');
            $body = collect($records)->map(fn ($rec) => '<tr>'.collect($rec)->map(fn ($c) => '<td style="border:1px solid #ccc;padding:4px">'.e((string) $c).'</td>')->implode('').'</tr>')->implode('');
            $html = '<h2 style="font-family:sans-serif">'.e($title).'</h2>'
                .'<table style="width:100%;border-collapse:collapse;font-family:sans-serif;font-size:11px"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>';

            return Pdf::loadHtml($html)->download($filename.'.pdf');
        }

        return response()->streamDownload(function () use ($headers, $records) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($records as $rec) {
                fputcsv($out, array_map(fn ($c) => $this->csvCell(is_string($c) ? $c : (string) $c), $rec));
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Neutralise spreadsheet formula injection (=,+,-,@,tab,CR) in a free-text CSV cell. */
    private function csvCell(?string $value): string
    {
        $v = (string) $value;

        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }

    /* ------------------------------------------------------------------ */
    /*  Create — show form to create leave request */
    /* ------------------------------------------------------------------ */

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.leave.manage'), 403);

        // The page-based form was replaced by the LeaveRequestDialog on the hub.
        return redirect()->route('hr.leave.index');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — view single leave request */
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
    /*  Store — submit a leave request */
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
    /*  Approve */
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
    /*  Decline */
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
     * @param  Collection<int, int>  $ids
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
