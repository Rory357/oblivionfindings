<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\RespiteBooking;
use App\Models\RespiteBookingRequest;
use App\Models\Shift;
use App\Models\ServiceContext;
use App\Models\User;
use App\Events\Respite\RespiteEvent;
use App\Services\Respite\RespiteCalendarProjector;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RespiteBookingController extends Controller
{
    public function index(): Response
    {
        $bookings = RespiteBooking::query()
            ->with(['client', 'coordinator'])
            ->whereIn('status', ['pending', 'confirmed', 'in_progress', 'completed'])
            ->orderByDesc('start_at')
            ->paginate(20);

        return Inertia::render('respite/bookings/index', [
            'bookings' => $bookings,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('respite/bookings/create', [
            'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->orderBy('first_name')->get(),
            'requests' => RespiteBookingRequest::where('status', 'approved')->with('client')->orderByDesc('requested_start')->get(),
            'pendingRequests' => RespiteBookingRequest::whereIn('status', ['submitted', 'under_review'])
                ->with('client')
                ->orderByDesc('requested_start')
                ->get(),
            'coordinators' => User::staff()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'booking_request_id' => 'nullable|exists:respite_booking_requests,id',
            'client_id' => 'required|exists:clients,id',
            'start_at' => 'required|date',
            'end_at' => 'required|date|after:start_at',
            'assigned_coordinator_id' => 'nullable|exists:users,id',
        ]);

        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        $booking = DB::transaction(function () use ($validated) {
            $booking = RespiteBooking::create($validated);

            $serviceContextId = Client::query()
                ->whereKey($booking->client_id)
                ->value('service_context_id');

            if (empty($serviceContextId)) {
                $serviceContextId = ServiceContext::defaultId();
            }

            Shift::firstOrCreate(
                ['respite_booking_id' => $booking->id],
                [
                    'client_id' => $booking->client_id,
                    'service_context_id' => $serviceContextId,
                    'user_id' => null,
                    'starts_at' => $booking->start_at,
                    'ends_at' => $booking->end_at,
                    'status' => 'scheduled',
                    'created_by' => auth()->id(),
                ]
            );

            return $booking;
        });

        event(new RespiteEvent('respite.booking.created', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return redirect()
            ->route('respite.bookings.show', $booking)
            ->with('success', 'Respite booking created.');
    }

    public function show(RespiteBooking $booking): Response
    {
        $booking->load(['client', 'coordinator', 'request', 'allocations', 'shift']);

        return Inertia::render('respite/bookings/show', [
            'booking' => $booking,
        ]);
    }

    public function update(Request $request, RespiteBooking $booking): RedirectResponse
    {
        $validated = $request->validate([
            'start_at' => 'sometimes|date',
            'end_at' => 'sometimes|date|after:start_at',
            'status' => 'sometimes|in:pending,confirmed,in_progress,completed,cancelled',
            'assigned_coordinator_id' => 'nullable|exists:users,id',
            'cancellation_reason' => 'nullable|string',
        ]);

        $validated['updated_by'] = auth()->id();
        $booking->update($validated);

        $booking->loadMissing('shift');
        if ($booking->shift && $booking->shift->status !== 'completed') {
            $booking->shift->update([
                'client_id' => $booking->client_id,
                'starts_at' => $booking->start_at,
                'ends_at' => $booking->end_at,
            ]);
        }

        event(new RespiteEvent('respite.booking.updated', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return back()->with('success', 'Booking updated.');
    }

    public function confirm(RespiteBooking $booking): RedirectResponse
    {
        $booking->update([
            'status' => 'confirmed',
            'updated_by' => auth()->id(),
        ]);

        app(RespiteCalendarProjector::class)->projectBooking($booking, auth()->id());

        event(new RespiteEvent('respite.booking.confirmed', [
            'id' => $booking->id,
            'client_id' => $booking->client_id,
            'status' => $booking->status,
        ]));

        return back()->with('success', 'Booking confirmed.');
    }
}
