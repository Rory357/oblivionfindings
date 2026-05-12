<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use Illuminate\Http\Request;

class SiteRoomController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $rooms = SiteHouseRoom::where('site_id', $site->id)
            ->with('assignedClient:id,first_name,last_name')
            ->with('history.client:id,first_name,last_name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $clients = $site->clients()
            ->select(['id', 'first_name', 'last_name'])
            ->orderBy('first_name')
            ->get();

        return inertia('sites/rooms/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
            ],
            'rooms' => $rooms->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'notes' => $r->notes,
                'is_active' => $r->is_active,
                'assigned_client' => $r->assignedClient ? [
                    'id' => $r->assignedClient->id,
                    'first_name' => $r->assignedClient->first_name,
                    'last_name' => $r->assignedClient->last_name,
                ] : null,
                'history' => $r->history->map(fn($h) => [
                    'id' => $h->id,
                    'client_id' => $h->client_id,
                    'client' => $h->client ? [
                        'id' => $h->client->id,
                        'first_name' => $h->client->first_name,
                        'last_name' => $h->client->last_name,
                    ] : null,
                    'assigned_from' => $h->assigned_from,
                    'assigned_until' => $h->assigned_until,
                    'notes' => $h->notes,
                    'created_at' => $h->created_at,
                ]),
            ]),
            'clients' => $clients->map(fn($c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
            ]),
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'assigned_client_id' => 'nullable|exists:clients,id',
            'is_assignable' => 'nullable|boolean',
        ]);

        $isAssignable = array_key_exists('is_assignable', $validated)
            ? (bool) $validated['is_assignable']
            : true;

        // Disallow seeding a client on a non-assignable room.
        if (!$isAssignable) {
            $validated['assigned_client_id'] = null;
        }

        $room = SiteHouseRoom::create([
            ...$validated,
            'site_id' => $site->id,
            'tenant_id' => $site->tenant_id,
            'is_active' => true,
            'is_assignable' => $isAssignable,
        ]);

        return redirect()->back()->with('success', 'Bedroom added.');
    }

    public function update(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('update', $site);
        abort_unless($room->site_id === $site->id, 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'assigned_client_id' => 'nullable|exists:clients,id',
            'is_assignable' => 'nullable|boolean',
        ]);

        // If the room is being flipped to non-assignable, clear any active
        // occupant and close their history row in one go.
        if (array_key_exists('is_assignable', $validated) && !$validated['is_assignable']) {
            if ($room->assigned_client_id) {
                $room->history()
                    ->where('client_id', $room->assigned_client_id)
                    ->whereNull('assigned_until')
                    ->latest('id')
                    ->first()
                    ?->update(['assigned_until' => now()->toDateString()]);
            }
            $validated['assigned_client_id'] = null;
            $validated['assigned_from'] = null;
            $validated['assigned_until'] = null;
        }

        // Legacy path: if a new client is assigned via update, mirror the
        // history-writing behaviour of the canonical assign() endpoint.
        if (
            array_key_exists('assigned_client_id', $validated)
            && $room->assigned_client_id !== $validated['assigned_client_id']
            && $validated['assigned_client_id']
        ) {
            $room->history()->create([
                'tenant_id' => $site->tenant_id,
                'client_id' => $validated['assigned_client_id'],
                'assigned_from' => now(),
                'assigned_by_user_id' => $request->user()->id,
            ]);
        }

        $room->update($validated);

        return redirect()->back()->with('success', 'Bedroom updated.');
    }

    public function destroy(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('update', $site);
        abort_unless($room->site_id === $site->id, 404);

        $room->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Bedroom deactivated.');
    }

    /**
     * Assign (or unassign) a client to a bedroom. Single canonical endpoint
     * used by both the Rooms tab and the Clients tab. Records history when
     * the occupant changes; closes the previous history row when an existing
     * occupant is replaced or cleared.
     */
    public function assign(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('update', $site);
        abort_unless($room->site_id === $site->id, 404);

        $validated = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'assigned_from' => ['nullable', 'date'],
            'assigned_until' => ['nullable', 'date', 'after_or_equal:assigned_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $newClientId = $validated['client_id'] ?? null;

        // Block assignments to non-assignable rooms (kitchens, lounges, etc.)
        // but allow the null path so callers can still clear an old stuck
        // assignment after the room is flipped to non-assignable.
        if ($newClientId && !$room->is_assignable) {
            return back()->with(
                'error',
                'This room is marked as a communal space and cannot have a client assigned.',
            )->withErrors([
                'client_id' => 'This room is not assignable to clients.',
            ]);
        }
        $previousClientId = $room->assigned_client_id;
        $today = now()->toDateString();

        // Close the previous open history row when the occupant is being
        // replaced or cleared.
        if ($previousClientId && $previousClientId !== $newClientId) {
            $room->history()
                ->where('client_id', $previousClientId)
                ->whereNull('assigned_until')
                ->latest('id')
                ->first()
                ?->update(['assigned_until' => $today]);
        }

        $room->update([
            'assigned_client_id' => $newClientId,
            'assigned_from' => $newClientId ? ($validated['assigned_from'] ?? $today) : null,
            'assigned_until' => $newClientId ? ($validated['assigned_until'] ?? null) : null,
        ]);

        if ($newClientId && $newClientId !== $previousClientId) {
            $room->history()->create([
                'tenant_id' => $site->tenant_id,
                'client_id' => $newClientId,
                'assigned_from' => $validated['assigned_from'] ?? $today,
                'assigned_until' => $validated['assigned_until'] ?? null,
                'assigned_by_user_id' => $request->user()->id,
                'notes' => $validated['notes'] ?? null,
            ]);
        }

        return back()->with(
            'success',
            $newClientId ? 'Client assigned to bedroom.' : 'Bedroom unassigned.'
        );
    }
}
