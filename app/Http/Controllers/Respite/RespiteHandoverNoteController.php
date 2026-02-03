<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteHandoverNote;
use App\Models\RespiteStay;
use App\Models\RespiteAuditLog;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteHandoverNoteController extends Controller
{
    public function index(Request $request): Response
    {
        $notes = RespiteHandoverNote::query()
            ->with(['stay.client', 'acknowledgedBy'])
            ->when($request->stay_id, fn ($q, $stayId) => $q->where('stay_id', $stayId))
            ->when($request->handover_type, fn ($q, $type) => $q->where('handover_type', $type))
            ->when($request->unacknowledged, fn ($q) => $q->whereNull('acknowledged_at'))
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('respite/handover-notes/index', [
            'notes' => $notes,
            'filters' => $request->only(['stay_id', 'handover_type', 'unacknowledged']),
        ]);
    }

    public function create(Request $request): Response
    {
        $stays = RespiteStay::query()
            ->with('client')
            ->whereIn('status', ['admitted', 'active', 'extended'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('respite/handover-notes/create', [
            'stays' => $stays,
            'stayId' => $request->stay_id,
            'handoverTypes' => [
                'shift_start' => 'Shift Start',
                'shift_end' => 'Shift End',
                'critical' => 'Critical Information',
                'medication' => 'Medication Related',
                'behaviour' => 'Behaviour Related',
                'general' => 'General',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stay_id' => 'required|exists:respite_stays,id',
            'handover_type' => 'required|in:shift_start,shift_end,critical,medication,behaviour,general',
            'notes' => 'required|string|max:10000',
            'sensitive_flag' => 'boolean',
        ]);

        $validated['created_by'] = auth()->id();

        $note = RespiteHandoverNote::create($validated);

        RespiteAuditLog::log(
            $note,
            RespiteAuditLog::ACTION_CREATED,
            auth()->id(),
            null,
            $validated,
            null,
            RespiteAuditLog::CATEGORY_HANDOVER
        );

        event(new RespiteEvent('respite.handover.created', [
            'id' => $note->id,
            'stay_id' => $note->stay_id,
            'handover_type' => $note->handover_type,
            'sensitive' => $note->sensitive_flag,
        ]));

        return redirect()
            ->route('respite.handover-notes.show', $note)
            ->with('success', 'Handover note created.');
    }

    public function show(RespiteHandoverNote $handoverNote): Response
    {
        $handoverNote->load(['stay.client', 'acknowledgedBy']);

        RespiteAuditLog::log(
            $handoverNote,
            RespiteAuditLog::ACTION_VIEWED,
            auth()->id(),
            null,
            null,
            null,
            RespiteAuditLog::CATEGORY_HANDOVER
        );

        return Inertia::render('respite/handover-notes/show', [
            'note' => $handoverNote,
        ]);
    }

    public function update(Request $request, RespiteHandoverNote $handoverNote): RedirectResponse
    {
        $oldValues = $handoverNote->only(['notes', 'handover_type', 'sensitive_flag']);

        $validated = $request->validate([
            'handover_type' => 'sometimes|in:shift_start,shift_end,critical,medication,behaviour,general',
            'notes' => 'sometimes|string|max:10000',
            'sensitive_flag' => 'sometimes|boolean',
        ]);

        $validated['updated_by'] = auth()->id();
        $handoverNote->update($validated);

        RespiteAuditLog::log(
            $handoverNote,
            RespiteAuditLog::ACTION_UPDATED,
            auth()->id(),
            $oldValues,
            $validated,
            null,
            RespiteAuditLog::CATEGORY_HANDOVER
        );

        event(new RespiteEvent('respite.handover.updated', [
            'id' => $handoverNote->id,
            'stay_id' => $handoverNote->stay_id,
        ]));

        return back()->with('success', 'Handover note updated.');
    }

    public function acknowledge(RespiteHandoverNote $handoverNote): RedirectResponse
    {
        if ($handoverNote->acknowledged_at) {
            return back()->with('error', 'Already acknowledged.');
        }

        $handoverNote->update([
            'acknowledged_by_user_id' => auth()->id(),
            'acknowledged_at' => now(),
            'updated_by' => auth()->id(),
        ]);

        RespiteAuditLog::log(
            $handoverNote,
            'acknowledged',
            auth()->id(),
            null,
            ['acknowledged_at' => now()->toIso8601String()],
            null,
            RespiteAuditLog::CATEGORY_HANDOVER
        );

        event(new RespiteEvent('respite.handover.acknowledged', [
            'id' => $handoverNote->id,
            'stay_id' => $handoverNote->stay_id,
            'acknowledged_by' => auth()->id(),
        ]));

        return back()->with('success', 'Handover acknowledged.');
    }

    public function forStay(RespiteStay $stay): Response
    {
        $notes = RespiteHandoverNote::query()
            ->where('stay_id', $stay->id)
            ->with('acknowledgedBy')
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('respite/handover-notes/for-stay', [
            'stay' => $stay->load('client'),
            'notes' => $notes,
        ]);
    }

    public function unacknowledged(): Response
    {
        $notes = RespiteHandoverNote::query()
            ->whereNull('acknowledged_at')
            ->with(['stay.client'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('respite/handover-notes/unacknowledged', [
            'notes' => $notes,
        ]);
    }
}
