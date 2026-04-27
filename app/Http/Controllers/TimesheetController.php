<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Operations\BillingService;
use App\Services\Operations\TimesheetHrSyncService;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\ShiftOperationalSnapshotService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimesheetController extends Controller
{
    public function approvals(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $pending = Timesheet::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name,email'])
            ->where('status', 'submitted')
            ->orderByDesc('submitted_at')
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->paginate(25)
            ->withQueryString();

        return inertia('operations/timesheets/approvals', [
            'timesheets' => $pending,
            'filters' => $request->only(['from', 'to', 'client_id', 'staff_id']),
        ]);
    }

    public function bulkApprove(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()
            ->whereIn('id', $data['ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->get();

        abort_if($timesheets->count() !== count($data['ids']), 403, 'You are not authorized to approve timesheets for one or more selected sites.');

        foreach ($timesheets as $timesheet) {
            $this->assertApprovalAllowed($timesheet, $auth);
        }

        foreach ($timesheets as $t) {
            $result = DB::transaction(function () use ($t, $auth, $data) {
                $timesheet = Timesheet::query()
                    ->lockForUpdate()
                    ->findOrFail($t->id);

                if ($timesheet->status === 'approved') {
                    return [
                        'timesheet' => $timesheet->fresh(['shift.client']) ?? $timesheet,
                        'approved_now' => false,
                    ];
                }

                if ($timesheet->status !== 'submitted') {
                    throw ValidationException::withMessages([
                        'timesheet' => 'Only submitted timesheets can be approved.',
                    ]);
                }

                $this->assertApprovalAllowed($timesheet, $auth);

                $timesheet->status = 'approved';
                $timesheet->approved_by = $auth->id;
                $timesheet->approved_at = now();
                $timesheet->decision_notes = $data['decision_notes'] ?? null;
                $timesheet->save();
                $this->syncApprovedTimesheet($timesheet);

                return [
                    'timesheet' => $timesheet->fresh(['shift.client']) ?? $timesheet,
                    'approved_now' => true,
                ];
            });

            /** @var \App\Models\Timesheet $approvedTimesheet */
            $approvedTimesheet = $result['timesheet'];
            if (! ($result['approved_now'] ?? false)) {
                continue;
            }

            $client = $approvedTimesheet->shift?->client;
            app(NotificationService::class)->notifyCrud($auth, 'approved', 'timesheet', $approvedTimesheet, $client, [
                'event_key' => 'timesheets.approved',
                'title' => 'Timesheet approved',
                'url' => url("/timesheets/{$approvedTimesheet->id}/edit"),
            ]);
        }

        return redirect()->back()->with('success', 'Selected timesheets approved.');
    }

    public function bulkReturnForChanges(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'returned_notes' => ['required', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()
            ->whereIn('id', $data['ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->get();

        abort_if($timesheets->count() !== count($data['ids']), 403, 'You are not authorized to return timesheets for one or more selected sites.');

        foreach ($timesheets as $t) {
            if ($t->status !== 'submitted') {
                continue;
            }
            $t->status = 'returned';
            $t->returned_by = $auth->id;
            $t->returned_at = now();
            $t->returned_notes = $data['returned_notes'];
            $t->approved_by = null;
            $t->approved_at = null;
            $t->decision_notes = null;
            $t->save();

            $t->load(['shift.client']);
            $client = $t->shift?->client;
            app(NotificationService::class)->notifyCrud($auth, 'returned', 'timesheet', $t, $client, [
                'event_key' => 'timesheets.returned',
                'title' => 'Timesheet returned for changes',
                'url' => url("/timesheets/{$t->id}/edit"),
            ]);
        }

        return redirect()->back()->with('success', 'Selected timesheets returned for changes.');
    }

    public function bulkReject(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'decision_notes' => ['required', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()
            ->whereIn('id', $data['ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->get();

        abort_if($timesheets->count() !== count($data['ids']), 403, 'You are not authorized to reject timesheets for one or more selected sites.');

        foreach ($timesheets as $t) {
            if ($t->status !== 'submitted') {
                continue;
            }
            $t->status = 'rejected';
            $t->approved_by = $auth->id;
            $t->approved_at = now();
            $t->decision_notes = $data['decision_notes'];
            $t->save();

            $t->load(['shift.client']);
            $client = $t->shift?->client;
            app(NotificationService::class)->notifyCrud($auth, 'rejected', 'timesheet', $t, $client, [
                'event_key' => 'timesheets.rejected',
                'title' => 'Timesheet rejected',
                'url' => url("/timesheets/{$t->id}/edit"),
            ]);
        }

        return redirect()->back()->with('success', 'Selected timesheets rejected.');
    }

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned') || $auth->canDo('hr.time.viewAny')), 403);

        $canApprove = $this->canReviewTimesheets($auth);
        $approvalMode = $request->query('mode') === 'approvals' && $canApprove;

        $status = $approvalMode ? 'submitted' : $request->query('status');
        $from = $request->query('from');
        $to = $request->query('to');
        $clientId = $request->query('client_id');
        $staffId = $request->query('staff_id');

        $q = Timesheet::query()
            ->with([
                'client:id,first_name,last_name',
                'staff:id,name,email',
                'shift:id,client_id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,expected_break_minutes,status',
                'shift.serviceContext:id,name',
            ])
            ->orderByDesc('work_date');

        $this->siteAccess()->applyTimesheetScope($q, $auth, $this->timesheetBypassPermissions());

        if (! $auth->canDo('timesheets.manageAny') && ! $approvalMode) {
            $q->where('user_id', $auth->id);
        }

        if ($status) {
            $q->where('status', $status);
        }
        if ($from) {
            $q->whereDate('work_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('work_date', '<=', $to);
        }
        if ($clientId) {
            $q->where('client_id', $clientId);
        }
        if ($staffId) {
            $q->where('user_id', $staffId);
        }

        $timesheets = $q->paginate(25)->withQueryString();

        $clients = $canApprove
            ? $this->siteAccess()->applyClientScope(Client::query(), $auth, $this->timesheetBypassPermissions())
                ->orderBy('first_name')
                ->get(['id', 'first_name', 'last_name'])
            : [];
        $staff = $canApprove
            ? $this->siteAccess()->applyStaffScope(\App\Models\User::staff(), $auth, $this->timesheetBypassPermissions())
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : [];

        return inertia('operations/timesheets/index', [
            'timesheets' => $timesheets,
            'filters' => [
                'status' => $status,
                'from' => $from,
                'to' => $to,
                'client_id' => $clientId,
                'staff_id' => $staffId,
                'mode' => $approvalMode ? 'approvals' : null,
            ],
            'approvalMode' => $approvalMode,
            'clients' => $clients,
            'staff' => $staff,
            'canApprove' => $canApprove,
            'canCreate' => $auth->canDo('timesheets.create'),
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.create'), 403);

        $shiftId = $request->query('shift_id');
        $shift = null;

        if ($shiftId) {
            $shift = Shift::query()
                ->with(['client:id,first_name,last_name', 'staff:id,name,email', 'serviceContext:id,name'])
                ->findOrFail($shiftId);
            $this->siteAccess()->assertCanAccessShift(
                $auth,
                $shift,
                $this->timesheetBypassPermissions(),
                'You are not authorized to create timesheets for that site.',
            );
            // Staff can only create timesheet from their own shift unless manageAny
            if (! $auth->canDo('timesheets.manageAny') && $shift->user_id !== $auth->id) {
                abort(403);
            }
        }

        $clients = $this->siteAccess()->applyClientScope(
            Client::query(),
            $auth,
            $this->timesheetBypassPermissions(),
        )->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return inertia('operations/timesheets/create', [
            'clients' => $clients,
            'shift' => $shift,
        ]);
    }

    public function show(Request $request, Timesheet $timesheet)
    {
        return $this->edit($request, $timesheet);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sleepover' => ['nullable', 'boolean'],
            'on_call' => ['nullable', 'boolean'],
            'allowance_notes' => ['nullable', 'string'],
            'public_holiday' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_residential_billable' => ['nullable', 'boolean'],
            // timesheets start as draft; submission is a separate action
        ]);

        $userId = $auth->id;
        $shiftId = $data['shift_id'] ?? null;
        $linkedShift = null;

        if ($shiftId) {
            $linkedShift = Shift::findOrFail($shiftId);
            $this->siteAccess()->assertCanAccessShift(
                $auth,
                $linkedShift,
                $this->timesheetBypassPermissions(),
                'You are not authorized to create timesheets for that site.',
            );
            if (! $auth->canDo('timesheets.manageAny') && $linkedShift->user_id !== $auth->id) {
                abort(403);
            }
            $userId = $linkedShift->user_id;
            $data['client_id'] = $linkedShift->client_id;

            if (Timesheet::query()
                ->where('shift_id', $linkedShift->id)
                ->where('user_id', $userId)
                ->exists()) {
                $message = 'A timesheet already exists for this shift and staff member.';

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $message,
                        'errors' => [
                            'shift_id' => [$message],
                        ],
                    ], 422);
                }

                return back()
                    ->with('error', $message)
                    ->withInput();
            }
        }

        $this->siteAccess()->assertCanAccessClientId(
            $auth,
            (int) $data['client_id'],
            $this->timesheetBypassPermissions(),
            'You are not authorized to create timesheets for that site.',
        );

        $snapshot = $this->draftSnapshot($data['client_id'], $linkedShift, $auth, $data['notes'] ?? null);

        $timesheet = Timesheet::create([
            'user_id' => $userId,
            'client_id' => $data['client_id'],
            'shift_id' => $shiftId,
            'shift_site_id' => $snapshot['site_id'] ?? null,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? null,
            'work_date' => $data['work_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => (int) ($data['break_minutes'] ?? $linkedShift?->expected_break_minutes ?? 0),
            'mileage_km' => $data['mileage_km'] ?? null,
            'sleepover' => $linkedShift ? (bool) $linkedShift->is_sleepover : (bool) ($data['sleepover'] ?? false),
            'on_call' => $linkedShift ? (bool) $linkedShift->is_on_call : (bool) ($data['on_call'] ?? false),
            'allowance_notes' => $data['allowance_notes'] ?? null,
            'public_holiday' => (bool) ($data['public_holiday'] ?? false),
            'notes' => $data['notes'] ?? null,
            'is_residential_billable' => (bool) ($data['is_residential_billable'] ?? false),
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? null,
            'shift_location_snapshot' => $snapshot['location'] ?? null,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? null,
            'client_name_snapshot' => $snapshot['client_name'] ?? null,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? null,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? [],
            'status' => 'draft',
            'created_by' => $auth->id,
        ]);

        app(TimesheetReconciliationService::class)->reconcile($timesheet);

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.created',
            'title' => 'Timesheet created',
            'url' => url("/timesheets/{$timesheet->id}/edit"),
            'target_user_ids' => [$timesheet->user_id],
        ]);

        return redirect()->route('timesheets.edit', $timesheet)->with('success', 'Timesheet created.');
    }

    public function edit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned') || $auth->canDo('hr.time.viewAny')), 403);

        if (! $auth->canDo('timesheets.manageAny') && ! $this->canReviewTimesheets($auth) && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        $timesheet->load([
            'client:id,first_name,last_name',
            'staff:id,name,email',
            'shift:id,client_id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,expected_break_minutes,status,user_id',
            'shift.serviceContext:id,name',
            'shift.staff:id,name,email',
        ]);
        $clients = $this->siteAccess()->applyClientScope(
            Client::query(),
            $auth,
            $this->timesheetBypassPermissions(),
        )->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return inertia('operations/timesheets/edit', [
            'timesheet' => $timesheet,
            'clients' => $clients,
            'canApprove' => $this->canReviewTimesheets($auth),
            'canSubmit' => $auth->canDo('timesheets.submit') && ($auth->canDo('timesheets.manageAny') || $timesheet->user_id === $auth->id),
            'canEdit' => $auth->canDo('timesheets.update')
                && ($auth->canDo('timesheets.manageAny') || $timesheet->user_id === $auth->id)
                && in_array($timesheet->status, ['draft', 'returned'], true),
        ]);
    }

    public function update(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.update'), 403);

        // Ownership check
        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        // Only editable while draft/returned (audit safety)
        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            return back()->with('error', 'Only draft or returned timesheets can be edited.');
        }

        // Payroll lock check: if timesheet is in a locked payroll run, prevent edits
        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be edited.');
        }

        if ($timesheet->is_protected_from_changes) {
            return back()->with('error', 'Approved or payroll-linked timesheets require a controlled correction workflow.');
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sleepover' => ['nullable', 'boolean'],
            'on_call' => ['nullable', 'boolean'],
            'allowance_notes' => ['nullable', 'string'],
            'public_holiday' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_residential_billable' => ['nullable', 'boolean'],
        ]);

        $linkedShift = $timesheet->shift_id ? Shift::find($timesheet->shift_id) : null;
        if ($linkedShift) {
            $data['client_id'] = $linkedShift->client_id;
        }

        $snapshot = $this->draftSnapshot($data['client_id'], $linkedShift, $timesheet->staff ?? $auth, $data['notes'] ?? $timesheet->notes);

        $timesheet->fill([
            'client_id' => $data['client_id'],
            'work_date' => $data['work_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'mileage_km' => $data['mileage_km'] ?? null,
            'sleepover' => $linkedShift ? (bool) $linkedShift->is_sleepover : (bool) ($data['sleepover'] ?? false),
            'on_call' => $linkedShift ? (bool) $linkedShift->is_on_call : (bool) ($data['on_call'] ?? false),
            'allowance_notes' => $data['allowance_notes'] ?? null,
            'public_holiday' => (bool) ($data['public_holiday'] ?? false),
            'notes' => $data['notes'] ?? null,
            'is_residential_billable' => (bool) ($data['is_residential_billable'] ?? false),
            'shift_site_id' => $snapshot['site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? $timesheet->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? $timesheet->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['location'] ?? $timesheet->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? $timesheet->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name'] ?? $timesheet->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? $timesheet->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? $timesheet->shift_type_snapshot ?? 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? $timesheet->coverage_roles_snapshot ?? [],
        ]);

        $timesheet->save();

        app(TimesheetReconciliationService::class)->reconcile($timesheet);

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.updated',
            'title' => 'Timesheet updated',
            'url' => url("/timesheets/{$timesheet->id}/edit"),
            'target_user_ids' => [$timesheet->user_id],
        ]);

        return redirect()->back()->with('success', 'Timesheet updated.');
    }

    public function submit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.submit'), 403);

        // Ownership check
        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        abort_unless(in_array($timesheet->status, ['draft', 'returned'], true), 403);

        // Payroll lock check
        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be submitted.');
        }

        if ($timesheet->is_protected_from_changes) {
            return back()->with('error', 'Approved or payroll-linked timesheets cannot be resubmitted.');
        }

        abort_if(
            $timesheet->linkedShiftIsCancelled(),
            422,
            'Timesheets linked to cancelled shifts cannot be submitted.',
        );

        app(TimesheetReconciliationService::class)->assertWorkflowAllowed($timesheet, 'submitted');

        $timesheet->status = 'submitted';
        $timesheet->submitted_at = now();
        $timesheet->submitted_by = $auth->id;
        // clear any prior decision
        $timesheet->approved_by = null;
        $timesheet->approved_at = null;
        $timesheet->decision_notes = null;
        $timesheet->returned_at = null;
        $timesheet->returned_by = null;
        $timesheet->returned_notes = null;
        $timesheet->save();

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'submitted', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.submitted',
            'title' => 'Timesheet submitted for approval',
            'url' => url("/timesheets/{$timesheet->id}/edit"),
            'include_entity_user' => false,
        ]);

        return redirect()->back()->with('success', 'Timesheet submitted.');
    }

    /**
     * Atomic save-and-resubmit for the inline /my-day edit sheet.
     *
     * Why: the original UI did a chained PUT /timesheets/{id} → POST submit
     * from the browser. If the submit failed after the PUT succeeded, the
     * timesheet was mutated but stuck in `returned`, leaving the worker with
     * no clear retry path. This endpoint runs both inside one DB transaction
     * so the row either fully transitions to `submitted` or stays untouched.
     */
    public function resubmit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless(
            $auth && $auth->canDo('timesheets.update') && $auth->canDo('timesheets.submit'),
            403,
        );

        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            return back()->with('error', 'Only draft or returned timesheets can be resubmitted.');
        }

        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be resubmitted.');
        }

        if ($timesheet->is_protected_from_changes) {
            return back()->with('error', 'Approved or payroll-linked timesheets require a controlled correction workflow.');
        }

        abort_if(
            $timesheet->linkedShiftIsCancelled(),
            422,
            'Timesheets linked to cancelled shifts cannot be resubmitted.',
        );

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sleepover' => ['nullable', 'boolean'],
            'on_call' => ['nullable', 'boolean'],
            'allowance_notes' => ['nullable', 'string'],
            'public_holiday' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_residential_billable' => ['nullable', 'boolean'],
        ]);

        app(TimesheetReconciliationService::class)->assertWorkflowAllowed($timesheet, 'submitted');

        $linkedShift = $timesheet->shift_id ? Shift::find($timesheet->shift_id) : null;
        if ($linkedShift) {
            $data['client_id'] = $linkedShift->client_id;
        }

        $snapshot = $this->draftSnapshot(
            $data['client_id'],
            $linkedShift,
            $timesheet->staff ?? $auth,
            $data['notes'] ?? $timesheet->notes,
        );

        DB::transaction(function () use ($timesheet, $data, $linkedShift, $snapshot, $auth) {
            $timesheet->fill([
                'client_id' => $data['client_id'],
                'work_date' => $data['work_date'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'break_minutes' => (int) ($data['break_minutes'] ?? 0),
                'mileage_km' => $data['mileage_km'] ?? null,
                'sleepover' => $linkedShift ? (bool) $linkedShift->is_sleepover : (bool) ($data['sleepover'] ?? false),
                'on_call' => $linkedShift ? (bool) $linkedShift->is_on_call : (bool) ($data['on_call'] ?? false),
                'allowance_notes' => $data['allowance_notes'] ?? null,
                'public_holiday' => (bool) ($data['public_holiday'] ?? false),
                'notes' => $data['notes'] ?? null,
                'is_residential_billable' => (bool) ($data['is_residential_billable'] ?? false),
                'shift_site_id' => $snapshot['site_id'] ?? $timesheet->shift_site_id,
                'shift_service_context_id' => $snapshot['service_context_id'] ?? $timesheet->shift_service_context_id,
                'shift_site_name_snapshot' => $snapshot['site_name'] ?? $timesheet->shift_site_name_snapshot,
                'shift_location_snapshot' => $snapshot['location'] ?? $timesheet->shift_location_snapshot,
                'service_context_name_snapshot' => $snapshot['service_context_name'] ?? $timesheet->service_context_name_snapshot,
                'client_name_snapshot' => $snapshot['client_name'] ?? $timesheet->client_name_snapshot,
                'staff_name_snapshot' => $snapshot['staff_name'] ?? $timesheet->staff_name_snapshot,
                'shift_type_snapshot' => $snapshot['shift_type'] ?? $timesheet->shift_type_snapshot ?? 'standard',
                'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? $timesheet->coverage_roles_snapshot ?? [],
            ]);

            $timesheet->status = 'submitted';
            $timesheet->submitted_at = now();
            $timesheet->submitted_by = $auth->id;
            $timesheet->approved_by = null;
            $timesheet->approved_at = null;
            $timesheet->decision_notes = null;
            $timesheet->returned_at = null;
            $timesheet->returned_by = null;
            $timesheet->returned_notes = null;

            $timesheet->save();

            app(TimesheetReconciliationService::class)->reconcile($timesheet);
        });

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'submitted', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.submitted',
            'title' => 'Timesheet updated and resubmitted',
            'url' => url("/timesheets/{$timesheet->id}/edit"),
            'include_entity_user' => false,
        ]);

        return redirect()->back()->with('success', 'Timesheet updated and resubmitted.');
    }

    public function approve(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $result = DB::transaction(function () use ($timesheet, $auth, $data) {
                $locked = Timesheet::query()
                    ->lockForUpdate()
                    ->findOrFail($timesheet->id);

                if ($locked->status === 'approved') {
                    return [
                        'timesheet' => $locked->fresh(['shift.client']) ?? $locked,
                        'approved_now' => false,
                    ];
                }

                if ($locked->status !== 'submitted') {
                    throw ValidationException::withMessages([
                        'timesheet' => 'Only submitted timesheets can be approved.',
                    ]);
                }

                $this->assertApprovalAllowed($locked, $auth);

                $locked->status = 'approved';
                $locked->approved_by = $auth->id;
                $locked->approved_at = now();
                $locked->decision_notes = $data['decision_notes'] ?? null;
                $locked->save();
                $this->syncApprovedTimesheet($locked);

                return [
                    'timesheet' => $locked->fresh(['shift.client']) ?? $locked,
                    'approved_now' => true,
                ];
            });
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        /** @var \App\Models\Timesheet $approvedTimesheet */
        $approvedTimesheet = $result['timesheet'];

        if (! ($result['approved_now'] ?? false)) {
            return redirect()->back()->with('success', 'Timesheet already approved.');
        }

        $client = $approvedTimesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'approved', 'timesheet', $approvedTimesheet, $client, [
            'event_key' => 'timesheets.approved',
            'title' => 'Timesheet approved',
            'url' => url("/timesheets/{$approvedTimesheet->id}/edit"),
        ]);

        return redirect()->back()->with('success', 'Timesheet approved.');
    }

    public function reject(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        abort_unless($timesheet->status === 'submitted', 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        if ($timesheet->is_payroll_segment_complete || $timesheet->payroll_reference) {
            return back()->with('error', 'Payroll-linked timesheets cannot be rejected after export preparation.');
        }

        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $decisionNotes = $data['decision_notes'] ?? $data['rejection_reason'] ?? null;
        if (! $decisionNotes) {
            return back()->withErrors(['decision_notes' => 'Decision notes are required.']);
        }

        $timesheet->status = 'rejected';
        $timesheet->approved_by = $auth->id;
        $timesheet->approved_at = now();
        $timesheet->decision_notes = $decisionNotes;
        $timesheet->save();

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'rejected', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.rejected',
            'title' => 'Timesheet rejected',
            'url' => url("/timesheets/{$timesheet->id}/edit"),
        ]);

        return redirect()->back()->with('success', 'Timesheet rejected.');
    }

    public function returnForChanges(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        abort_unless($timesheet->status === 'submitted', 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        if ($timesheet->is_payroll_segment_complete || $timesheet->payroll_reference) {
            return back()->with('error', 'Payroll-linked timesheets cannot be returned after export preparation.');
        }

        $data = $request->validate([
            'returned_notes' => ['nullable', 'string', 'max:5000'],
            'return_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $returnedNotes = $data['returned_notes'] ?? $data['return_reason'] ?? null;
        if (! $returnedNotes) {
            return back()->withErrors(['returned_notes' => 'Returned notes are required.']);
        }

        $timesheet->status = 'returned';
        $timesheet->returned_by = $auth->id;
        $timesheet->returned_at = now();
        $timesheet->returned_notes = $returnedNotes;
        // clear decision
        $timesheet->approved_by = null;
        $timesheet->approved_at = null;
        $timesheet->decision_notes = null;
        $timesheet->save();

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'returned', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.returned',
            'title' => 'Timesheet returned for changes',
            'url' => url("/timesheets/{$timesheet->id}/edit"),
        ]);

        return redirect()->back()->with('success', 'Timesheet returned for changes.');
    }

    /**
     * Check if a timesheet is locked by a payroll run.
     */
    protected function isLockedByPayroll(Timesheet $timesheet): bool
    {
        if (! $timesheet->work_date) {
            return false;
        }

        return HrPayrollRun::where('tenant_id', $timesheet->user?->tenant_id)
            ->whereIn('status', ['locked', 'exported'])
            ->where('period_start', '<=', $timesheet->work_date)
            ->where('period_end', '>=', $timesheet->work_date)
            ->exists();
    }

    protected function syncApprovedTimesheet(Timesheet $timesheet): void
    {
        $timesheet->loadMissing([
            'shift.site:id,name',
            'shift.client:id,first_name,last_name,site_id',
            'shift.serviceContext:id,name',
            'shift.staff:id,name',
            'client:id,first_name,last_name',
            'staff:id,name',
            'user.hrEmployeeProfile',
        ]);

        $snapshot = app(ShiftOperationalSnapshotService::class)->snapshotForTimesheet($timesheet);

        $timesheet->forceFill([
            'shift_site_id' => $snapshot['shift_site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['shift_service_context_id'] ?? $timesheet->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['shift_site_name_snapshot'] ?: $timesheet->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['shift_location_snapshot'] ?: $timesheet->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name_snapshot'] ?: $timesheet->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name_snapshot'] ?: $timesheet->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name_snapshot'] ?: $timesheet->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type_snapshot'] ?: $timesheet->shift_type_snapshot ?: 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles_snapshot'] ?? $timesheet->coverage_roles_snapshot ?? [],
        ])->saveQuietly();

        $freshTimesheet = $timesheet->fresh();
        $missingSnapshotFields = array_keys(array_filter([
            'client_name_snapshot' => blank($freshTimesheet?->client_name_snapshot),
            'staff_name_snapshot' => blank($freshTimesheet?->staff_name_snapshot),
            'shift_type_snapshot' => blank($freshTimesheet?->shift_type_snapshot),
        ]));

        if ($missingSnapshotFields !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'timesheet' => 'This timesheet is missing required snapshot data and cannot be approved safely: '.implode(', ', $missingSnapshotFields).'.',
            ]);
        }

        app(TimesheetHrSyncService::class)->syncToHr($freshTimesheet);
        app(BillingService::class)->generateFromTimesheet($freshTimesheet);
    }

    /**
     * @return array<string, mixed>
     */
    protected function draftSnapshot(int $clientId, ?Shift $linkedShift, User $staff, ?string $location = null): array
    {
        $snapshots = app(ShiftOperationalSnapshotService::class);

        if ($linkedShift) {
            return $snapshots->snapshotForShift($linkedShift, $linkedShift->staff ?? $staff);
        }

        return $snapshots->snapshotForClient(
            Client::query()->with(['site:id,name', 'serviceContext:id,name'])->find($clientId),
            $staff,
            $location,
        );
    }

    /**
     * Payroll adjustments pending queue: approved amendments on payroll-linked
     * timesheets that have not yet been applied / processed.
     */
    public function payrollAdjustmentsPending(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        $amendments = TimesheetAmendment::query()
            ->where('status', TimesheetAmendment::STATUS_APPROVED)
            ->where('payroll_adjustment_required', true)
            ->whereNull('applied_at')
            ->with([
                'timesheet:id,shift_id,user_id,client_id,work_date,starts_at,ends_at,status,staff_name_snapshot,client_name_snapshot,shift_site_name_snapshot,payroll_reference,exported_to_payroll_at',
                'timesheet.shift:id,starts_at,ends_at',
                'requestedBy:id,name',
                'reviewedBy:id,name',
            ])
            ->orderBy('reviewed_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/timesheets/payroll-adjustments', [
            'amendments' => $amendments->through(fn (TimesheetAmendment $a) => [
                'id' => $a->id,
                'timesheet_id' => $a->timesheet_id,
                'staff_name' => $a->timesheet?->staff_name_snapshot ?? 'Unknown',
                'client_name' => $a->timesheet?->client_name_snapshot ?? '',
                'site_name' => $a->timesheet?->shift_site_name_snapshot ?? '',
                'work_date' => $a->timesheet?->work_date?->toDateString(),
                'original_values' => $a->original_values,
                'proposed_values' => $a->proposed_values,
                'reason' => $a->reason,
                'requested_by' => $a->requestedBy?->name,
                'reviewed_by' => $a->reviewedBy?->name,
                'reviewed_at' => $a->reviewed_at?->toIso8601String(),
                'payroll_reference' => $a->timesheet?->payroll_reference,
                'timesheet_url' => url("/operations/timesheets/{$a->timesheet_id}/edit"),
            ]),
        ]);
    }

    /**
     * Mark a payroll-linked amendment as processed (payroll adjustment handled externally).
     */
    public function markPayrollAdjustmentProcessed(Request $request, TimesheetAmendment $amendment)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        if ($amendment->status !== TimesheetAmendment::STATUS_APPROVED) {
            return back()->with('error', 'Only approved amendments can be marked as processed.');
        }

        if (! $amendment->payroll_adjustment_required) {
            return back()->with('error', 'This amendment does not require payroll adjustment.');
        }

        if ($amendment->applied_at) {
            return back()->with('success', 'This adjustment has already been marked as processed.');
        }

        $amendment->update(['applied_at' => now()]);

        \App\Services\AuditLogger::log('timesheet.amendment.payroll_processed', $amendment->timesheet, [
            'amendment_id' => $amendment->id,
            'processed_by' => $auth->id,
        ]);

        return back()->with('success', 'Payroll adjustment marked as processed.');
    }

    protected function assertApprovalAllowed(Timesheet $timesheet, User $auth): void
    {
        $this->assertCanAccessTimesheet($auth, $timesheet);

        if ((int) $timesheet->user_id === (int) $auth->id) {
            abort(403, 'You cannot approve your own timesheet.');
        }

        if ($timesheet->linkedShiftIsCancelled()) {
            abort(422, 'Timesheets linked to cancelled shifts cannot be approved.');
        }

        app(TimesheetReconciliationService::class)->assertWorkflowAllowed($timesheet, 'approved');
    }

    protected function canReviewTimesheets(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->canDo('timesheets.approve')
            || $user->canDo('timesheets.manageAny')
            || $user->canDo('hr.time.manage')
            || $user->canDo('hr.time.approveTeam');
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function timesheetBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function assertCanAccessTimesheet(User $auth, Timesheet $timesheet): void
    {
        $this->siteAccess()->assertCanAccessTimesheet(
            $auth,
            $timesheet,
            $this->timesheetBypassPermissions(),
            'You are not authorized to access timesheets for this site.',
        );
    }
}
