<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use Illuminate\Http\Request;

class TimesheetController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.viewAny'), 403);

        $status = $request->query('status');
        $from = $request->query('from');
        $to = $request->query('to');

        $q = Timesheet::query()
            ->with(['client:id,first_name,last_name', 'staff:id,name,email'])
            ->orderByDesc('work_date');

        if (!$auth->canDo('timesheets.manageAny')) {
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

        $timesheets = $q->paginate(25)->withQueryString();

        return inertia('timesheets/index', [
            'timesheets' => $timesheets,
            'filters' => compact('status', 'from', 'to'),
            'canApprove' => $auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny'),
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
            'status' => ['nullable', 'in:draft,submitted'],
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
            'status' => $data['status'] ?? 'draft',
            'created_by' => $auth->id,
        ]);

        return redirect()->route('timesheets.edit', $timesheet)->with('success', 'Timesheet created.');
    }

    public function edit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.viewAny'), 403);

        if (!$auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $timesheet->load(['client:id,first_name,last_name', 'staff:id,name,email', 'shift:id,starts_at,ends_at']);
        $clients = Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        return inertia('timesheets/edit', [
            'timesheet' => $timesheet,
            'clients' => $clients,
            'canApprove' => $auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny'),
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

        // If already approved, only managers can change
        if ($timesheet->status === 'approved' && !$auth->canDo('timesheets.manageAny')) {
            abort(403);
        }

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,submitted,approved,rejected'],
            'approve' => ['nullable', 'boolean'],
            'reject' => ['nullable', 'boolean'],
        ]);

        // Approve/reject controls
        if (($data['approve'] ?? false) || ($data['reject'] ?? false)) {
            abort_unless($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny'), 403);
            if (($data['approve'] ?? false)) {
                $timesheet->status = 'approved';
                $timesheet->approved_by = $auth->id;
                $timesheet->approved_at = now();
            } else {
                $timesheet->status = 'rejected';
                $timesheet->approved_by = $auth->id;
                $timesheet->approved_at = now();
            }
        } else {
            // Normal update
            $timesheet->fill([
                'client_id' => $data['client_id'],
                'work_date' => $data['work_date'],
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'break_minutes' => (int) ($data['break_minutes'] ?? 0),
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? $timesheet->status,
            ]);
        }

        $timesheet->save();

        return redirect()->back()->with('success', 'Timesheet updated.');
    }
}
