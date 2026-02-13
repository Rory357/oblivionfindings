<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\LocationHardware;
use App\Models\SiteRoom;
use App\Models\Integration\IntegrationSiteConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SiteHardwareController extends Controller
{
    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $hardware = LocationHardware::where('site_id', $site->id)
            ->with('room:id,name')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $rooms = SiteRoom::where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get();

        $integrations = IntegrationSiteConfig::where('site_id', $site->id)
            ->where('is_active', true)
            ->get();

        $assets = $site->assets()
            ->select(['id', 'name', 'asset_tag'])
            ->orderBy('name')
            ->get();

        $user = $request->user();

        return inertia('sites/hardware/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'hardware' => $hardware,
            'rooms' => $rooms,
            'integrations' => $integrations,
            'assets' => $assets,
            'categories' => LocationHardware::categories(),
            'can' => [
                'manage_hardware' => $user->canDo('sites.manage'),
                'manage_integrations' => $user->canDo('sites.manage'),
            ],
        ]);
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:' . implode(',', array_keys(LocationHardware::categories())),
            'provider' => 'nullable|string|max:100',
            'room_id' => 'nullable|exists:site_rooms,id',
            'asset_tag' => 'nullable|string|max:100',
            'serial' => 'nullable|string|max:255',
            'mac' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        LocationHardware::create([
            ...$validated,
            'provider' => $validated['provider'] ?? 'manual',
            'tenant_id' => $request->user()->tenant_id,
            'site_id' => $site->id,
            'status' => LocationHardware::STATUS_UNKNOWN,
        ]);

        return redirect()->back()->with('success', 'Hardware added successfully.');
    }

    public function update(Request $request, Site $site, LocationHardware $hardware)
    {
        $this->authorize('update', $site);
        abort_unless($hardware->site_id === $site->id, 404);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:' . implode(',', array_keys(LocationHardware::categories())),
            'provider' => 'nullable|string|max:100',
            'room_id' => 'nullable|exists:site_rooms,id',
            'asset_tag' => 'nullable|string|max:100',
            'serial' => 'nullable|string|max:255',
            'mac' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        $hardware->update($validated);

        return redirect()->back()->with('success', 'Hardware updated successfully.');
    }

    public function destroy(Request $request, Site $site, LocationHardware $hardware)
    {
        $this->authorize('update', $site);
        abort_unless($hardware->site_id === $site->id, 404);

        $hardware->delete();

        return redirect()->back()->with('success', 'Hardware deleted successfully.');
    }

    public function assignRoom(Request $request, Site $site, LocationHardware $hardware)
    {
        $this->authorize('update', $site);
        abort_unless($hardware->site_id === $site->id, 404);

        $validated = $request->validate([
            'room_id' => 'nullable|exists:site_rooms,id',
        ]);

        $hardware->update(['room_id' => $validated['room_id']]);

        return redirect()->back()->with('success', 'Hardware room assignment updated.');
    }

    public function linkAsset(Request $request, Site $site, LocationHardware $hardware)
    {
        $this->authorize('update', $site);
        abort_unless($hardware->site_id === $site->id, 404);

        $validated = $request->validate([
            'linked_asset_id' => 'nullable|exists:assets,id',
        ]);

        $hardware->update(['linked_asset_id' => $validated['linked_asset_id']]);

        return redirect()->back()->with('success', 'Hardware asset link updated.');
    }

    public function refreshStatus(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        return redirect()->back()->with('info', 'Status refresh will be available when integration adapters are configured.');
    }

    public function manageRooms(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $request->validate([
            'action' => 'required|in:add,rename,reorder,delete',
        ]);

        $action = $request->input('action');

        switch ($action) {
            case 'add':
                $request->validate([
                    'name' => 'required|string|max:255',
                ]);

                $maxSort = SiteRoom::where('site_id', $site->id)->max('sort_order') ?? 0;

                SiteRoom::create([
                    'tenant_id' => $request->user()->tenant_id,
                    'site_id' => $site->id,
                    'name' => $request->input('name'),
                    'sort_order' => $maxSort + 1,
                ]);

                return redirect()->back()->with('success', 'Room added successfully.');

            case 'rename':
                $request->validate([
                    'room_id' => 'required|exists:site_rooms,id',
                    'name' => 'required|string|max:255',
                ]);

                $room = SiteRoom::where('site_id', $site->id)
                    ->findOrFail($request->input('room_id'));

                $room->update(['name' => $request->input('name')]);

                return redirect()->back()->with('success', 'Room renamed successfully.');

            case 'reorder':
                $request->validate([
                    'rooms' => 'required|array',
                    'rooms.*.id' => 'required|exists:site_rooms,id',
                    'rooms.*.sort_order' => 'required|integer|min:0',
                ]);

                foreach ($request->input('rooms') as $roomData) {
                    SiteRoom::where('site_id', $site->id)
                        ->where('id', $roomData['id'])
                        ->update(['sort_order' => $roomData['sort_order']]);
                }

                return redirect()->back()->with('success', 'Rooms reordered successfully.');

            case 'delete':
                $request->validate([
                    'room_id' => 'required|exists:site_rooms,id',
                ]);

                $room = SiteRoom::where('site_id', $site->id)
                    ->findOrFail($request->input('room_id'));

                $room->delete();

                return redirect()->back()->with('success', 'Room deleted successfully.');
        }

        return redirect()->back();
    }
}
