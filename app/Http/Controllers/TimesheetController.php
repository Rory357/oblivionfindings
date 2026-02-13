<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    public function approvals(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        $pending = Timesheet::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name,email'])
            ->where('status', 'submitted')
            ->orderByDesc('submitted_at')
            ->paginate(25)
            ->withQueryString();

        return inertia('timesheets/approvals', [
            'timesheets' => $pending,
            'filters' => $request->only(['from', 'to', 'client_id', 'staff_id']),
        ]);
    }

    public function bulkApprove(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()->whereIn('id', $data['ids'])->get();

        foreach ($timesheets as $t) {
            if ($t->status !== 'submitted') {
                continue;
            }
            $t->status = 'approved';
            $t->approved_by = $auth->id;
            $t->approved_at = now();
            $t->decision_notes = $data['decision_notes'] ?? null;
            $t->save();

            $t->load(['shift.client']);
            $client = $t->shift?->client;
            app(NotificationService::class)->notifyCrud($auth, 'approved', 'timesheet', $t, $client, [
                'event_key' => 'timesheets.approved',
                'title' => 'Timesheet approved',
                'url' => url("/timesheets/{$t->id}/edit"),
            ]);
        }

        return redirect()->back()->with('success', 'Selected timesheets approved.');
    }

    public function bulkReturnForChanges(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'returned_notes' => ['required', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()->whereIn('id', $data['ids'])->get();

        foreach ($timesheets as $t) {
            if ($t->status !== 'submitted') {
                continue;
            }
            $t->status = 'draft';
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
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'decision_notes' => ['required', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()->whereIn('id', $data['ids'])->get();

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
        abort_unless($auth && ($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned')), 403);

        $canApprove = $auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny');
        $approvalMode = $request->query('mode') === 'approvals' && $canApprove;

        $status = $approvalMode ? 'submitted' : $request->query('status');
        $from = $request->query('from');
        $to = $request->query('to');
        $clientId = $request->query('client_id');
        $staffId = $request->query('staff_id');

        $q = Timesheet::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name,email'])
            ->orderByDesc('work_date');

        if (!$auth->canDo('timesheets.manageAny') && !$approvalMode) {
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

        $clients = $canApprove ? Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']) : [];
        $staff = $canApprove ? \App\Models\User::staff()->orderBy('name')->get(['id', 'name', 'email']) : [];

        return inertia('timesheets/index', [
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
            $shift = Shift::query()->with('client:id,first_name,last_name')->findOrFail($shiftId);
            // Staff can only create timesheet from their own shift unless manageAny
            if (!$auth->canDo('timesheets.manageAny') && $shift->user_id !== $auth->id) {
                abort(403);
            }
        }

        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return inertia('timesheets/create', [
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
            'notes' => ['nullable', 'string'],
            // timesheets start as draft; submission is a separate action
        ]);

        $userId = $auth->id;
        $shiftId = $data['shift_id'] ?? null;

        if ($shiftId) {
            $shift = Shift::findOrFail($shiftId);
            if (!$auth->canDo('timesheets.manageAny') && $shift->user_id !== $auth->id) {
                abort(403);
            }
            $userId = $shift->user_id;
        }

        $timesheet = Timesheet::create([
            'user_id' => $userId,
            'client_id' => $data['client_id'],
            'shift_id' => $shiftId,
            'work_date' => $data['work_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'status' => 'draft',
            'created_by' => $auth->id,
        ]);

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
        abort_unless($auth && ($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned')), 403);

        if (!$auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $timesheet->load(['client:id,first_name,last_name', 'staff:id,name,email', 'shift:id,starts_at,ends_at']);
        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return inertia('timesheets/edit', [
            'timesheet' => $timesheet,
            'clients' => $clients,
            'canApprove' => $auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny'),
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
        if (!$auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        // Only editable while draft/returned (audit safety)
        if (!in_array($timesheet->status, ['draft', 'returned'], true)) {
            return back()->with('error', 'Only draft or returned timesheets can be edited.');
        }

        // Payroll lock check: if timesheet is in a locked payroll run, prevent edits
        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be edited.');
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'notes' => ['nullable', 'string'],
        ]);

        $timesheet->fill([
            'client_id' => $data['client_id'],
            'work_date' => $data['work_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'notes' => $data['notes'] ?? null,
        ]);

        $timesheet->save();

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
        if (!$auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        abort_unless(in_array($timesheet->status, ['draft', 'returned'], true), 403);

        // Payroll lock check
        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be submitted.');
        }

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

    public function approve(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);
        abort_unless($timesheet->status === 'submitted', 403);

        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $timesheet->status = 'approved';
        $timesheet->approved_by = $auth->id;
        $timesheet->approved_at = now();
        $timesheet->decision_notes = $data['decision_notes'] ?? null;
        $timesheet->save();

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'approved', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.approved',
            'title' => 'Timesheet approved',
            'url' => url("/timesheets/{$timesheet->id}/edit"),
        ]);

        return redirect()->back()->with('success', 'Timesheet approved.');
    }

    public function reject(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);
        abort_unless($timesheet->status === 'submitted', 403);

        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $decisionNotes = $data['decision_notes'] ?? $data['rejection_reason'] ?? null;
        if (!$decisionNotes) {
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
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);
        abort_unless($timesheet->status === 'submitted', 403);

        $data = $request->validate([
            'returned_notes' => ['nullable', 'string', 'max:5000'],
            'return_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $returnedNotes = $data['returned_notes'] ?? $data['return_reason'] ?? null;
        if (!$returnedNotes) {
            return back()->withErrors(['returned_notes' => 'Returned notes are required.']);
        }

        $timesheet->status = 'draft';
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
        if (!$timesheet->work_date) {
            return false;
        }

        return HrPayrollRun::where('tenant_id', $timesheet->user?->tenant_id)
            ->whereIn('status', ['locked', 'exported'])
            ->where('period_start', '<=', $timesheet->work_date)
            ->where('period_end', '>=', $timesheet->work_date)
            ->exists();
    }
}
