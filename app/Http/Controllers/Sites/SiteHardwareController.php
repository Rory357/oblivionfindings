<?php

namespace App\Http\Controllers\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\SiteTypePlanPin;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Services\Sites\Profile\SiteProfileOperationsPresenter;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;

class SiteHardwareController extends Controller
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly SiteTypePlanService $typePlans,
        private readonly SiteProfileOperationsPresenter $profileOperations,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $payload = $this->profileOperations->hardware($request->user(), $site);
        unset($payload['locked'], $payload['href']);

        return inertia('sites/hardware/index', $payload);
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
    ) {
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
        if (! empty($validated['room_id'])) {
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

    public function pinDevice(Request $request, Site $site, int $device)
    {
        $this->authorize('update', $site);

        $data = $request->validate([
            'x' => ['required', 'numeric', 'between:0,1'],
            'y' => ['required', 'numeric', 'between:0,1'],
            'label' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $tenantId = $request->user()?->tenant_id ?? $request->user()?->organization_id ?? $site->tenant_id ?? 1;
        $deviceModel = Device::query()->forTenant($tenantId)->findOrFail($device);

        $belongsToSite = $this->registry->forSite($tenantId, $site->id)
            ->whereKey($deviceModel->id)
            ->exists();
        abort_unless($belongsToSite, 404);

        $plan = $this->typePlans->currentEditable($site);
        abort_unless($plan, 409, 'Build a plan before pinning hardware.');

        $pin = $plan->pins()->updateOrCreate(
            [
                'kind' => SiteTypePlanPin::KIND_DEVICE,
                'device_id' => $deviceModel->id,
            ],
            [
                'tenant_id' => $plan->tenant_id,
                'subkind' => $deviceModel->subcategory ?? $deviceModel->category,
                'label' => $data['label'] ?? $deviceModel->name,
                'notes' => $data['notes'] ?? null,
                'meta' => ['stale' => false],
                'x' => $data['x'],
                'y' => $data['y'],
            ],
        );

        if ($request->wantsJson()) {
            return response()->json([
                'pin' => $this->typePlans->serializePin($pin->fresh()),
            ]);
        }

        return back()->with('success', 'Hardware pinned to plan.');
    }

    public function unpinDevice(Request $request, Site $site, int $device)
    {
        $this->authorize('update', $site);

        $plan = $this->typePlans->currentEditable($site);
        abort_unless($plan, 404);

        $plan->pins()
            ->where('kind', SiteTypePlanPin::KIND_DEVICE)
            ->where('device_id', $device)
            ->delete();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('success', 'Hardware pin removed.');
    }
}
