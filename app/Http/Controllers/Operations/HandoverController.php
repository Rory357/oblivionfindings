<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\ShiftHandover;
use App\Models\Shift;
use Illuminate\Http\Request;

class HandoverController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $handovers = ShiftHandover::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'outgoingShift:id,starts_at,ends_at,client_id,user_id',
                'incomingShift:id,starts_at,ends_at,client_id,user_id',
                'client:id,first_name,last_name',
                'outgoingStaff:id,name',
                'incomingStaff:id,name',
            ])
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when(!empty($filters['date_from']), fn ($q) => $q->whereDate('created_at', '>=', $filters['date_from']))
            ->when(!empty($filters['date_to']), fn ($q) => $q->whereDate('created_at', '<=', $filters['date_to']))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return inertia('operations/handovers/Index', [
            'handovers' => $handovers,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.viewAny'), 403);

        $handover = ShiftHandover::where('organization_id', $auth->organization_id)
            ->with([
                'outgoingShift.client:id,first_name,last_name',
                'outgoingShift.staff:id,name',
                'incomingShift.client:id,first_name,last_name',
                'incomingShift.staff:id,name',
                'client:id,first_name,last_name',
                'outgoingStaff:id,name',
                'incomingStaff:id,name',
            ])
            ->findOrFail($handover);

        return inertia('operations/handovers/Show', [
            'handover' => $handover,
        ]);
    }

    public function store(Request $request, $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.edit'), 403);

        $outgoingShift = Shift::findOrFail($shift);

        $data = $request->validate([
            'handover_notes' => ['required', 'string', 'max:5000'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'incoming_shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'incoming_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_mood' => ['nullable', 'string', 'max:255'],
            'tasks_pending' => ['nullable', 'json'],
            'medications_due' => ['nullable', 'json'],
            'incidents_to_note' => ['nullable', 'json'],
        ]);

        ShiftHandover::create([
            'organization_id' => $auth->organization_id,
            'outgoing_shift_id' => $outgoingShift->id,
            'incoming_shift_id' => $data['incoming_shift_id'] ?? null,
            'client_id' => $data['client_id'] ?? $outgoingShift->client_id,
            'outgoing_staff_id' => $auth->id,
            'incoming_staff_id' => $data['incoming_staff_id'] ?? null,
            'handover_notes' => $data['handover_notes'],
            'client_mood' => $data['client_mood'] ?? null,
            'tasks_pending' => isset($data['tasks_pending']) ? json_decode($data['tasks_pending'], true) : null,
            'medications_due' => isset($data['medications_due']) ? json_decode($data['medications_due'], true) : null,
            'incidents_to_note' => isset($data['incidents_to_note']) ? json_decode($data['incidents_to_note'], true) : null,
        ]);

        return redirect()->back();
    }

    public function acknowledge(Request $request, $handover)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('shifts.edit'), 403);

        $handover = ShiftHandover::where('organization_id', $auth->organization_id)->findOrFail($handover);

        $handover->update([
            'acknowledged_at' => now(),
        ]);

        return redirect()->back();
    }
}
