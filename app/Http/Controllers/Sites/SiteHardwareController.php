<?php

namespace App\Http\Controllers\Sites;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\Integration\IntegrationSiteConfig;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\SiteTypePlan;
use App\Models\SiteTypePlanPin;
use App\Services\Integration\UnifiOperationalBridgeService;
use App\Services\Sites\SiteTypePlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SiteHardwareController extends Controller
{
    public function __construct(
        private readonly DeviceRegistryService $registry,
        private readonly SiteTypePlanService $typePlans,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $user = $request->user();
        $typePlan = $this->typePlans->summaryFor($site);
        $currentPlan = $this->typePlans->currentEditable($site);
        $devicePins = $currentPlan
            ? $currentPlan->pins()
                ->where('kind', SiteTypePlanPin::KIND_DEVICE)
                ->get()
                ->keyBy('device_id')
            : collect();

        // Canonical device list (from Security & Devices).
        // This page is a read-only context view; provider config + device CRUD
        // live in the Security & Devices module.
        $devices = $this->registry->visibleForSite($user, $site->id)
            ->with(['assignments' => fn ($q) => $q->active()])
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(function (Device $d) use ($devicePins) {
                $active = $d->assignments->first(fn ($a) => $a->released_at === null);
                $externalRef = is_array($d->external_ref) ? $d->external_ref : [];
                $meta = is_array($d->meta) ? $d->meta : [];
                $planPin = $devicePins->get($d->id);

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
                    'plan_pin' => $planPin ? $this->typePlans->serializePin($planPin) : null,
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
            'typePlan' => $typePlan,
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
