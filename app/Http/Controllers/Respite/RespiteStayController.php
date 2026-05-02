<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteBooking;
use App\Models\RespiteStay;
use App\Events\Respite\RespiteEvent;
use App\Services\Respite\RespiteShiftSync;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RespiteStayController extends Controller
{
    public function index(): Response
    {
        $stays = RespiteStay::with(['client', 'booking'])
            ->latest()
            ->paginate(25);

        return Inertia::render('respite/stays/index', [
            'stays' => $stays,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:respite_bookings,id',
            'client_id' => 'required|exists:clients,id',
        ]);

        $booking = RespiteBooking::with('client')->findOrFail($validated['booking_id']);
        $this->authorize('view', $booking->client);

        if ((int) $booking->client_id !== (int) $validated['client_id']) {
            throw ValidationException::withMessages([
                'client_id' => 'The stay client must match the respite booking client.',
            ]);
        }

        $validated['status'] = 'admitted';
        $validated['created_by'] = auth()->id();
        $validated['actual_start'] = now();

        $stay = RespiteStay::create($validated);

        event(new RespiteEvent('respite.stay.created', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return redirect()
            ->route('respite.stays.show', $stay)
            ->with('success', 'Respite stay created.');
    }

    public function show(RespiteStay $stay): Response
    {
        $stay->load([
            'client',
            'booking.coordinator',
            'evidencePack',
            'handovers',
            'communications',
            'dailyNotes',
            'riskPlanActivations',
            'createdByUser',
        ]);
        $this->authorize('view', $stay->client);

        return Inertia::render('respite/stays/show', [
            'stay' => $stay,
        ]);
    }

    public function checkIn(RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client');
        $this->authorize('view', $stay->client);

        $stay->update([
            'status' => 'active',
            'actual_start' => $stay->actual_start ?? now(),
            'updated_by' => auth()->id(),
        ]);

        app(RespiteShiftSync::class)->checkInStay($stay, $stay->actual_start, auth()->id());

        event(new RespiteEvent('respite.stay.checked_in', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay checked in.');
    }

    public function extend(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client', 'booking');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'new_end' => 'required|date',
        ]);

        $newEnd = Carbon::parse($validated['new_end']);
        $actualStart = $stay->actual_start ?: $stay->booking?->start_at;

        if ($actualStart && $newEnd->lte($actualStart)) {
            throw ValidationException::withMessages([
                'new_end' => 'The new end must be after the stay start.',
            ]);
        }

        $stay->update([
            'status' => 'extended',
            'actual_end' => $newEnd,
            'updated_by' => auth()->id(),
        ]);

        app(RespiteShiftSync::class)->extendStay($stay, $newEnd);

        event(new RespiteEvent('respite.stay.extended', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay extended.');
    }

    public function discharge(Request $request, RespiteStay $stay): RedirectResponse
    {
        $stay->loadMissing('client');
        $this->authorize('view', $stay->client);

        $validated = $request->validate([
            'discharge_summary' => 'required|string',
        ]);

        $stay->update([
            'status' => 'discharged',
            'actual_end' => now(),
            'discharge_summary' => $validated['discharge_summary'],
            'updated_by' => auth()->id(),
        ]);

        app(RespiteShiftSync::class)->dischargeStay($stay, $validated['discharge_summary'], $stay->actual_end, auth()->id());

        event(new RespiteEvent('respite.stay.discharged', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay discharged.');
    }
}
