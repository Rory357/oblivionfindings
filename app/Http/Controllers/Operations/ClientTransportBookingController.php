<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientTransportBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $data['scheduled_at'] = $this->toUtc($data['scheduled_at']);

        ClientTransportBooking::create([
            ...$data,
            'organization_id' => $client->organization_id,
            'client_id' => $client->id,
            'status' => 'requested',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Transport booked.');
    }

    /**
     * The wizard sends a naive datetime-local string (worker wall-clock).
     * Interpret it in the worker timezone and store UTC so it round-trips to
     * the same wall-clock on display. See reference: store UTC, convert at the
     * app.worker_timezone boundary.
     */
    private function toUtc(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $value,
            config('app.worker_timezone', 'Pacific/Auckland'),
        )->utc();
    }

    public function update(Request $request, Client $client, ClientTransportBooking $booking)
    {
        $this->authorize('update', $client);
        abort_unless($booking->client_id === $client->id, 404);

        abort_if($booking->fleet_booking_id || $booking->assessed_at || $booking->events()->exists(), 409,
            'Open this request in Transport to change it. Its recorded history is retained.');

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

        if (array_key_exists('scheduled_at', $data)) {
            $data['scheduled_at'] = $this->toUtc($data['scheduled_at']);
        }

        DB::transaction(function () use ($booking, $client, $data): void {
            $currentClient = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $currentClient);
            $current = ClientTransportBooking::where('client_id', $currentClient->id)->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_if($current->fleet_booking_id || $current->assessed_at || $current->events()->exists(), 409,
                'This request changed. Open it in Transport to retain its operational history.');
            $current->update($data);
        }, 3);

        return back()->with('success', 'Transport booking updated.');
    }

    public function destroy(Request $request, Client $client, ClientTransportBooking $booking)
    {
        $this->authorize('update', $client);
        abort_unless($booking->client_id === $client->id, 404);

        abort_if($booking->fleet_booking_id || $booking->assessed_at || $booking->events()->exists(), 409,
            'Cancel this request in Transport. Recorded transport history cannot be deleted.');

        DB::transaction(function () use ($booking, $client): void {
            $currentClient = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $this->authorize('update', $currentClient);
            $current = ClientTransportBooking::where('client_id', $currentClient->id)->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_if($current->fleet_booking_id || $current->assessed_at || $current->events()->exists(), 409,
                'This request now has transport history and cannot be deleted.');
            $current->delete();
        }, 3);

        return back()->with('success', 'Transport booking removed.');
    }
}
