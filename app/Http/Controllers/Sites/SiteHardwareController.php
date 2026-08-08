<?php

namespace App\Http\Controllers\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\SiteTypePlan;
use App\Models\SiteTypePlanPin;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Services\Sites\Profile\SiteProfileOperationsPresenter;
use App\Services\Sites\SitePhysicalRoomService;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use UnexpectedValueException;

class SiteHardwareController extends Controller
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly SiteTypePlanService $typePlans,
        private readonly SiteProfileOperationsPresenter $profileOperations,
        private readonly SitePhysicalRoomService $physicalRooms,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $payload = $this->profileOperations->hardware($request->user(), $site);
        unset($payload['locked'], $payload['href']);

        return inertia('sites/hardware/index', $payload);
    }

    // ── Remaining room-management methods ────────────────────────
    // Sites owns physical room management, while every provider's placement
    // writes canonical DeviceAssignment state. UniFi retains its hardened
    // integration bridge; other providers use the generic registry service.
    public function assignRoom(
        Request $request,
        Site $site,
        int $hardware,
        UnifiOperationalBridgeService $unifi,
    ) {
        $this->authorize('update', $site);

        $device = $this->registry->visibleForSite($request->user(), $site->id)
            ->findOrFail($hardware);

        $validated = $request->validate([
            'room_id' => ['nullable', 'integer'],
        ]);

        $roomId = isset($validated['room_id']) ? (int) $validated['room_id'] : null;

        if ($device->provider === 'unifi') {
            $room = $roomId === null
                ? null
                : SiteRoom::query()
                    ->where('site_id', $site->id)
                    ->whereHas('site')
                    ->find($roomId);
            abort_unless($roomId === null || $room !== null, 404);
            $unifi->syncRoomAssignment(
                $device,
                $room,
                $request->user()?->id,
                (int) $site->id,
            );
        } else {
            try {
                $this->registry->placeWithinSite(
                    device: $device,
                    expectedSiteId: (int) $site->id,
                    roomId: $roomId,
                    actorId: (int) $request->user()->id,
                );
            } catch (UnexpectedValueException) {
                abort(404);
            }
        }

        return redirect()->back()->with('success', 'Hardware room assignment updated.');
    }

    public function manageRooms(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validatedAction = $request->validate([
            'action' => 'required|in:add,rename,reorder,delete',
        ]);

        $action = $validatedAction['action'];

        switch ($action) {
            case 'add':
                $validated = $request->validate([
                    'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
                ]);

                $this->physicalRooms->createCanonicalRoom($site, $validated['name']);

                return redirect()->back()->with('success', 'Room added successfully.');

            case 'rename':
                $validated = $request->validate([
                    'room_id' => ['required', 'integer'],
                    'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
                ]);

                $room = SiteRoom::query()
                    ->where('site_id', $site->id)
                    ->findOrFail((int) $validated['room_id']);
                $this->physicalRooms->renameCanonicalRoom($site, $room, $validated['name']);

                return redirect()->back()->with('success', 'Room renamed successfully.');

            case 'reorder':
                $validated = $request->validate([
                    'rooms' => ['required', 'array', 'min:1'],
                    'rooms.*.id' => ['required', 'integer', 'distinct'],
                    'rooms.*.sort_order' => ['required', 'integer', 'min:0', 'distinct'],
                ]);

                $this->physicalRooms->reorderCanonicalRooms($site, $validated['rooms']);

                return redirect()->back()->with('success', 'Rooms reordered successfully.');

            case 'delete':
                $validated = $request->validate([
                    'room_id' => ['required', 'integer'],
                ]);

                $room = SiteRoom::query()
                    ->where('site_id', $site->id)
                    ->findOrFail((int) $validated['room_id']);
                $this->physicalRooms->deleteCanonicalRoom($site, $room);

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

        $deviceModel = Device::query()->findOrFail($device);

        $belongsToSite = $this->registry->visibleForSite($request->user(), $site->id)
            ->whereKey($deviceModel->id)
            ->exists();
        abort_unless($belongsToSite, 404);

        $plan = $this->hardwareDraft($site, $request->user()?->id);
        $existingPin = $plan->pins()
            ->where('kind', SiteTypePlanPin::KIND_DEVICE)
            ->where('device_id', $deviceModel->id)
            ->first();
        $pins = $this->typePlans->replacePins($plan, [[
            'id' => $existingPin?->id,
            'kind' => SiteTypePlanPin::KIND_DEVICE,
            'device_id' => $deviceModel->id,
            'subkind' => null,
            'label' => $data['label'] ?? $deviceModel->name,
            'notes' => $data['notes'] ?? null,
            'meta' => array_merge(is_array($existingPin?->meta) ? $existingPin->meta : [], [
                'stale' => false,
                'device_category' => $deviceModel->category,
                'device_subcategory' => $deviceModel->subcategory,
            ]),
            'x' => $data['x'],
            'y' => $data['y'],
            'rotation_deg' => $existingPin?->rotation_deg ?? 0,
            'sort_order' => $existingPin?->sort_order ?? ((int) $plan->pins()->max('sort_order')) + 1,
        ]]);
        $pin = $pins->firstWhere('device_id', $deviceModel->id);
        abort_unless($pin, 500, 'The device pin could not be saved.');

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

        abort_unless($this->registry->visibleForSite($request->user(), $site->id)->whereKey($device)->exists(), 404);
        $plan = $this->hardwareDraft($site, $request->user()?->id);
        $pin = $plan->pins()
            ->where('kind', SiteTypePlanPin::KIND_DEVICE)
            ->where('device_id', $device)
            ->first();

        if ($pin) {
            $this->typePlans->deletePin($site, $pin);
        }

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('success', 'Hardware pin removed.');
    }

    private function hardwareDraft(Site $site, ?int $userId): SiteTypePlan
    {
        if ($draft = $this->typePlans->currentDraft($site)) {
            return $draft;
        }

        abort_unless($this->typePlans->currentPublished($site), 409, 'Build a plan before pinning hardware.');

        return $this->typePlans->cloneToDraft($site, $userId);
    }
}
