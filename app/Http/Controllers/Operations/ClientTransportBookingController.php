<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTransportBooking;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Client-scoped transport bookings — the "Book transport" workflow on the
 * client profile Transport tab. Lightweight request records that sit alongside
 * the fleet module's trip history (fleet stays the system of record for
 * actual vehicle movements).
 */
class ClientTransportBookingController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $this->authorize('update', $client);

        $data = $request->validate([
            'purpose' => ['required', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'date'],
            'vehicle' => ['nullable', 'string', 'max:120'],
            'driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'escort_required' => ['nullable', 'boolean'],
            'return_trip' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        ClientTransportBooking::create([
            ...$data,
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'status' => 'requested',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Transport booked.');
    }

    public function update(Request $request, Client $client, ClientTransportBooking $booking)
    {
        $this->authorize('update', $client);
        abort_unless($booking->client_id === $client->id, 404);

        $data = $request->validate([
            'purpose' => ['sometimes', 'string', 'max:255'],
            'destination' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['sometimes', 'date'],
            'vehicle' => ['nullable', 'string', 'max:120'],
            'driver_id' => ['nullable', 'integer', 'exists:users,id'],
            'escort_required' => ['nullable', 'boolean'],
            'return_trip' => ['nullable', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(ClientTransportBooking::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $booking->update($data);

        return back()->with('success', 'Transport booking updated.');
    }

    public function destroy(Request $request, Client $client, ClientTransportBooking $booking)
    {
        $this->authorize('update', $client);
        abort_unless($booking->client_id === $client->id, 404);

        $booking->delete();

        return back()->with('success', 'Transport booking removed.');
    }
}
