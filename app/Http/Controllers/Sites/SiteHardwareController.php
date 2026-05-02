<?php

namespace App\Http\Controllers\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Services\Integration\UnifiOperationalBridgeService;
use Illuminate\Http\Request;

class SiteHardwareController extends Controller
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $user = $request->user();
        $tenantId = $user?->tenant_id ?? $user?->organization_id ?? $site->tenant_id ?? 1;

        // Canonical device list (from Security & Devices).
        // This page is a read-only context view; provider config + device CRUD
        // live in the Security & Devices module.
        $devices = $this->registry->forSite($tenantId, $site->id)
            ->with(['assignments' => fn ($q) => $q->active()])
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(function (Device $d) {
                $active = $d->assignments->first(fn ($a) => $a->released_at === null);
                $externalRef = is_array($d->external_ref) ? $d->external_ref : [];
                $meta = is_array($d->meta) ? $d->meta : [];

                return [
                    'id' => $d->id,
                    'device_uid' => $d->device_uid,
                    'name' => $d->name,
                    'domain' => $d->domain,
                    'category' => $d->category,
                    'subcategory' => $d->subcategory,
                    'manufacturer' => $d->manufacturer,
                    'model' => $d->model,
                    'serial_number' => $d->serial_number,
                    'mac_address' => $d->mac_address,
                    'asset_tag' => $d->asset_tag,
                    'status' => $d->status?->value,
                    'health_status' => $d->health_status?->value,
                    'provider' => $d->provider,
                    'provider_entity_id' => $externalRef['provider_entity_id'] ?? null,
                    'provider_type' => $meta['provider_type'] ?? $externalRef['provider_type'] ?? null,
                    'last_seen_at' => $d->last_seen_at?->toISOString(),
                    'battery_level' => $d->battery_level,
                    'firmware_version' => $d->firmware_version,
                    'ip_address' => $d->ip_address,
                    'notes' => $d->notes,
                    'assignment_type' => $active?->assignable_type,
                    'assignment_id' => $active?->assignable_id,
                ];
            });

        $rooms = SiteRoom::where('site_id', $site->id)
            ->orderBy('sort_order')
            ->get();

        // UniFi context for the site — surfaced as a separate prop so the
        // front-end can show "UniFi connected / N devices / last synced at"
        // independently of the canonical device list. Always present (even
        // when no UniFi site config exists) so callers don't have to guard.
        $unifiConfig = IntegrationSiteConfig::query()
            ->where('site_id', $site->id)
            ->where('provider', 'unifi')
            ->first();

        $unifiDevices = $devices->filter(fn (array $d) => $d['provider'] === 'unifi')->values();

        $unifi = [
            'is_configured' => $unifiConfig !== null,
            'mapped_external_site_id' => $unifiConfig?->mapped_external_site_id,
            'mapped_external_site_name' => $unifiConfig?->mapped_external_site_name,
            'status' => $unifiConfig?->status,
            'device_count' => $unifiDevices->count(),
        ];

        return inertia('sites/hardware/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
            ],
            'devices' => $devices,
            'rooms' => $rooms,
            'unifi' => $unifi,
            'can' => [
                'manage_hardware' => $user?->canDo('siteHardware.manage') ?? false,
            ],
        ]);
    }

    // ── Remaining room-management methods ────────────────────────
    // Sites still owns room management itself, but UniFi room placement now
    // writes canonical DeviceAssignment state first and only mirrors the
    // linked LocationHardware row as compatibility metadata.
    public function assignRoom(
        Request $request,
        Site $site,
        int $hardware,
        UnifiOperationalBridgeService $runtime,
    )
    {
        $this->authorize('update', $site);

        $device = Device::query()
            ->forTenant($site->tenant_id ?? 1)
            ->byProvider('unifi')
            ->findOrFail($hardware);

        $currentSiteId = $runtime->resolveSiteId($device);
        abort_unless($currentSiteId === null || $currentSiteId === $site->id, 404);

        $validated = $request->validate([
            'room_id' => 'nullable|exists:site_rooms,id',
        ]);

        $room = null;
        if (!empty($validated['room_id'])) {
            $room = SiteRoom::query()
                ->where('site_id', $site->id)
                ->findOrFail($validated['room_id']);
        }

        $runtime->syncRoomAssignment($device, $room, $request->user()?->id);

        return redirect()->back()->with('success', 'Hardware room assignment updated.');
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
                $tenantId = $site->tenant_id ?? $request->user()?->tenant_id ?? $request->user()?->organization_id ?? 1;

                SiteRoom::create([
                    'tenant_id' => $tenantId,
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
