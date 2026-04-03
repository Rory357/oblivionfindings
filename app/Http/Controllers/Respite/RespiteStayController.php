<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteStay;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
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

        return Inertia::render('respite/stays/show', [
            'stay' => $stay,
        ]);
    }

    public function checkIn(RespiteStay $stay): RedirectResponse
    {
        $stay->update([
            'status' => 'active',
            'actual_start' => $stay->actual_start ?? now(),
            'updated_by' => auth()->id(),
        ]);

        event(new RespiteEvent('respite.stay.checked_in', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay checked in.');
    }

    public function extend(Request $request, RespiteStay $stay): RedirectResponse
    {
        $request->validate([
            'new_end' => 'required|date|after:now',
        ]);

        $stay->update([
            'status' => 'extended',
            'actual_end' => $request->new_end,
            'updated_by' => auth()->id(),
        ]);

        event(new RespiteEvent('respite.stay.extended', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay extended.');
    }

    public function discharge(Request $request, RespiteStay $stay): RedirectResponse
    {
        $validated = $request->validate([
            'discharge_summary' => 'required|string',
        ]);

        $stay->update([
            'status' => 'discharged',
            'actual_end' => now(),
            'discharge_summary' => $validated['discharge_summary'],
            'updated_by' => auth()->id(),
        ]);

        event(new RespiteEvent('respite.stay.discharged', [
            'id' => $stay->id,
            'client_id' => $stay->client_id,
            'status' => $stay->status,
        ]));

        return back()->with('success', 'Stay discharged.');
    }
}
