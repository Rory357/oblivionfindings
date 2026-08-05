<?php

namespace App\Http\Controllers\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\SiteTypePlan;
use App\Models\SiteTypePlanPin;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Services\Sites\Profile\SiteProfileOperationsPresenter;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        $device = $this->registry->visibleForSite($request->user(), $site->id)
            ->byProvider('unifi')
            ->findOrFail($hardware);

        $currentSiteId = $runtime->resolveSiteId($device);
        abort_unless($currentSiteId === null || $currentSiteId === $site->id, 404);

        $validated = $request->validate([
            'room_id' => ['nullable', 'integer'],
        ]);

        $room = null;
        $roomId = $validated['room_id'] ?? null;
        if ($roomId !== null) {
            $room = SiteRoom::query()
                ->where('site_id', $site->id)
                ->whereHas('site')
                ->find($roomId);
            abort_unless($room, 404);
        }

        $runtime->syncRoomAssignment($device, $room, $request->user()?->id, $site->id);

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

                DB::transaction(function () use ($site, $validated): void {
                    $site = $this->lockedSite($site);
                    $name = trim($validated['name']);
                    $this->ensureRoomNameAvailable($site, $name);
                    $maxSort = SiteRoom::query()->where('site_id', $site->id)->max('sort_order') ?? 0;

                    SiteRoom::create([
                        'site_id' => $site->id,
                        'name' => $name,
                        'sort_order' => $maxSort + 1,
                    ]);
                });

                return redirect()->back()->with('success', 'Room added successfully.');

            case 'rename':
                $validated = $request->validate([
                    'room_id' => ['required', 'integer'],
                    'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/'],
                ]);

                DB::transaction(function () use ($site, $validated): void {
                    $site = $this->lockedSite($site);
                    $room = $this->lockedRoom($site, (int) $validated['room_id']);
                    $name = trim($validated['name']);
                    $this->ensureRoomNameAvailable($site, $name, $room->id);
                    $room->update(['name' => $name]);
                });

                return redirect()->back()->with('success', 'Room renamed successfully.');

            case 'reorder':
                $validated = $request->validate([
                    'rooms' => ['required', 'array', 'min:1'],
                    'rooms.*.id' => ['required', 'integer', 'distinct'],
                    'rooms.*.sort_order' => ['required', 'integer', 'min:0', 'distinct'],
                ]);

                DB::transaction(function () use ($site, $validated): void {
                    $site = $this->lockedSite($site);
                    $roomIds = collect($validated['rooms'])->pluck('id')->map(fn ($id) => (int) $id)->all();
                    $rooms = SiteRoom::query()
                        ->where('site_id', $site->id)
                        ->whereKey($roomIds)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                    abort_unless($rooms->count() === count($roomIds), 404);

                    foreach ($validated['rooms'] as $roomData) {
                        $rooms->get((int) $roomData['id'])->update(['sort_order' => $roomData['sort_order']]);
                    }
                });

                return redirect()->back()->with('success', 'Rooms reordered successfully.');

            case 'delete':
                $validated = $request->validate([
                    'room_id' => ['required', 'integer'],
                ]);

                DB::transaction(function () use ($site, $validated): void {
                    $site = $this->lockedSite($site);
                    $room = $this->lockedRoom($site, (int) $validated['room_id']);
                    $hasDevices = DeviceAssignment::query()
                        ->where('assignable_type', DeviceAssignment::TARGET_ROOM)
                        ->where('assignable_id', $room->id)
                        ->whereNull('released_at')
                        ->lockForUpdate()
                        ->exists();
                    abort_if($hasDevices, 409, 'Move devices out of this room before deleting it.');

                    $room->delete();
                });

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

    private function lockedSite(Site $site): Site
    {
        return Site::query()->whereKey($site->id)->lockForUpdate()->firstOrFail();
    }

    private function lockedRoom(Site $site, int $roomId): SiteRoom
    {
        return SiteRoom::query()
            ->where('site_id', $site->id)
            ->whereKey($roomId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ensureRoomNameAvailable(Site $site, string $name, ?int $ignoreRoomId = null): void
    {
        $exists = SiteRoom::query()
            ->where('site_id', $site->id)
            ->where('name', $name)
            ->when($ignoreRoomId, fn ($query) => $query->whereKeyNot($ignoreRoomId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'A hardware room with this name already exists at the Site.',
            ]);
        }
    }
}
