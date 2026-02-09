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
        ]);

        $room = SiteHouseRoom::create([
            ...$validated,
            'site_id' => $site->id,
            'is_active' => true,
        ]);

        return redirect()->back()->with('success', 'Bedroom added.');
    }

    public function update(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'assigned_client_id' => 'nullable|exists:clients,id',
        ]);

        // If assigning a new client, record in history
        if ($room->assigned_client_id !== $validated['assigned_client_id'] && $validated['assigned_client_id']) {
            $room->history()->create([
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

        $room->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Bedroom deactivated.');
    }
}
