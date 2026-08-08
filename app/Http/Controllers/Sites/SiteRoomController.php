<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ClientPersonalAsset;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Services\Sites\SiteClientPlacementService;
use App\Services\Sites\SitePhysicalRoomService;
use Illuminate\Http\Request;

class SiteRoomController extends Controller
{
    public function __construct(
        private readonly SiteClientPlacementService $placements,
        private readonly SitePhysicalRoomService $physicalRooms,
    ) {}

    public function index(Request $request, Site $site)
    {
        $this->authorize('view', $site);

        $rooms = SiteHouseRoom::query()
            ->where('site_id', $site->id)
            ->with([
                'assignedClient:id,first_name,last_name,preferred_name,profile_photo_path,status,key_worker_id,safeguarding_flag,risk_level',
                'assignedClient.keyWorker:id,name',
                'history' => fn ($h) => $h
                    ->orderByDesc('id')
                    ->with([
                        'client:id,first_name,last_name',
                        'assignedBy:id,name',
                    ]),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        // Site assets in any of these rooms.
        $siteAssetsByRoom = Asset::query()
            ->where('site_id', $site->id)
            ->whereNotNull('room_id')
            ->orderBy('name')
            ->get([
                'id', 'room_id', 'name', 'asset_tag', 'category', 'status',
                'risk_level', 'location',
            ])
            ->groupBy('room_id');

        // Personal assets (read-only — surfaced per occupant's room, with
        // the owner client preloaded so the UI can link back to their
        // profile.)
        $personalAssetsByRoom = ClientPersonalAsset::query()
            ->where('site_id', $site->id)
            ->whereNotNull('room_id')
            ->with('client:id,first_name,last_name,preferred_name')
            ->orderBy('name')
            ->get([
                'id', 'room_id', 'client_id', 'name', 'category', 'status',
                'condition', 'photo_path',
            ])
            ->groupBy('room_id');

        $assignableAssetPool = Asset::query()
            ->where('site_id', $site->id)
            ->whereNull('room_id')
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name', 'asset_tag', 'category', 'status']);

        $clients = $site->clients()
            ->select(['id', 'first_name', 'last_name', 'preferred_name', 'status'])
            ->orderBy('first_name')
            ->get();

        // Hero stats and alert chips reflect ACTIVE rooms only. Deactivated
        // rooms can be revealed via the "Show inactive" filter but they
        // shouldn't pad the counts (you can't assign a client to one).
        $activeRooms = $rooms->where('is_active', true);
        $activeCommunal = $activeRooms->where('is_assignable', false);
        $assignableCollection = $activeRooms->where('is_assignable', true);
        $occupied = $assignableCollection->whereNotNull('assigned_client_id')->count();
        $bedrooms = $assignableCollection->count();

        return inertia('sites/rooms/index', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'region' => $site->region,
                'is_active' => (bool) $site->is_active,
                'is_high_risk' => (bool) $site->is_high_risk,
            ],
            'rooms' => $rooms->map(function ($r) use ($siteAssetsByRoom, $personalAssetsByRoom) {
                $client = $r->assignedClient;

                return [
                    'id' => $r->id,
                    'site_room_id' => $r->site_room_id,
                    'name' => $r->name,
                    'notes' => $r->notes,
                    'is_active' => (bool) $r->is_active,
                    'is_assignable' => (bool) ($r->is_assignable ?? true),
                    'sort_order' => $r->sort_order,
                    'assigned_from' => $r->assigned_from?->toDateString(),
                    'assigned_until' => $r->assigned_until?->toDateString(),
                    'assigned_client' => $client ? [
                        'id' => $client->id,
                        'first_name' => $client->first_name,
                        'last_name' => $client->last_name,
                        'preferred_name' => $client->preferred_name,
                        'status' => $client->status,
                        'risk_level' => $client->risk_level,
                        'safeguarding_flag' => (bool) $client->safeguarding_flag,
                        'profile_photo_url' => $client->profile_photo_url,
                        'key_worker' => $client->keyWorker ? [
                            'id' => $client->keyWorker->id,
                            'name' => $client->keyWorker->name,
                        ] : null,
                    ] : null,
                    'assets' => ($siteAssetsByRoom[$r->id] ?? collect())->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->name,
                        'asset_tag' => $a->asset_tag,
                        'category' => $a->category,
                        'status' => $a->status,
                        'risk_level' => $a->risk_level,
                        'location' => $a->location,
                    ])->values(),
                    'personal_assets' => ($personalAssetsByRoom[$r->id] ?? collect())->map(fn ($p) => [
                        'id' => $p->id,
                        'client_id' => $p->client_id,
                        'client' => $p->client ? [
                            'id' => $p->client->id,
                            'first_name' => $p->client->first_name,
                            'last_name' => $p->client->last_name,
                            'preferred_name' => $p->client->preferred_name,
                        ] : null,
                        'name' => $p->name,
                        'category' => $p->category,
                        'status' => $p->status,
                        'condition' => $p->condition,
                    ])->values(),
                    'history' => $r->history->map(fn ($h) => [
                        'id' => $h->id,
                        'client' => $h->client ? [
                            'id' => $h->client->id,
                            'first_name' => $h->client->first_name,
                            'last_name' => $h->client->last_name,
                        ] : null,
                        'assigned_from' => $h->assigned_from?->toDateString(),
                        'assigned_until' => $h->assigned_until?->toDateString(),
                        'assigned_by' => $h->assignedBy?->name,
                        'notes' => $h->notes,
                    ])->values(),
                ];
            })->values(),
            'clients' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'preferred_name' => $c->preferred_name,
                'status' => $c->status,
            ])->values(),
            'availableAssets' => $assignableAssetPool->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'asset_tag' => $a->asset_tag,
                'category' => $a->category,
                'status' => $a->status,
            ])->values(),
            'summary' => [
                'total' => $rooms->count(),
                'active' => $activeRooms->count(),
                'inactive' => $rooms->where('is_active', false)->count(),
                'bedrooms' => $bedrooms,
                'communal' => $activeCommunal->count(),
                'occupied' => $occupied,
                'available' => $bedrooms - $occupied,
                'occupancy_percent' => $bedrooms > 0
                    ? (int) round(($occupied / $bedrooms) * 100)
                    : 0,
                'assets_linked' => $siteAssetsByRoom->flatten()->count(),
            ],
            'alerts' => [
                'empty_bedrooms' => $assignableCollection->whereNull('assigned_client_id')->count(),
                'safeguarding' => $assignableCollection
                    ->filter(fn ($r) => $r->assignedClient && $r->assignedClient->safeguarding_flag)
                    ->count(),
                'missing_key_worker' => $assignableCollection
                    ->filter(fn ($r) => $r->assignedClient && ! $r->assignedClient->key_worker_id)
                    ->count(),
            ],
            'can_edit' => (bool) $request->user()?->canDo('sites.update'),
        ]);
    }

    public function seedDefaults(Request $request, Site $site)
    {
        $this->authorize('update', $site);
        abort_unless(in_array($site->type, ['house', 'residential'], true), 404);

        $defaults = [
            ['name' => 'Bedroom 1', 'assignable' => true],
            ['name' => 'Bedroom 2', 'assignable' => true],
            ['name' => 'Bedroom 3', 'assignable' => true],
            ['name' => 'Kitchen', 'assignable' => false],
            ['name' => 'Lounge', 'assignable' => false],
            ['name' => 'Bathroom', 'assignable' => false],
            ['name' => 'Laundry', 'assignable' => false],
            ['name' => 'Hallway', 'assignable' => false],
            ['name' => 'Garage', 'assignable' => false],
            ['name' => 'Garden / Exterior', 'assignable' => false],
        ];

        foreach ($defaults as $index => $room) {
            $existing = SiteHouseRoom::query()
                ->where('site_id', $site->id)
                ->where('name', $room['name'])
                ->first();
            $payload = [
                'is_active' => true,
                'is_assignable' => $room['assignable'],
                'sort_order' => $index + 1,
            ];
            $existing
                ? $this->physicalRooms->updateResidentialRoom($site, $existing, $payload, $request->user())
                : $this->physicalRooms->createResidentialRoom($site, ['name' => $room['name'], ...$payload]);
        }

        return back()->with('success', 'Standard rooms added.');
    }

    public function store(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'notes' => 'nullable|string',
            'is_assignable' => 'nullable|boolean',
        ]);

        $isAssignable = array_key_exists('is_assignable', $validated)
            ? (bool) $validated['is_assignable']
            : true;

        $this->physicalRooms->createResidentialRoom($site, [
            ...$validated,
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
            'is_assignable' => 'nullable|boolean',
        ]);

        $this->physicalRooms->updateResidentialRoom($site, $room, $validated, $request->user());

        return redirect()->back()->with('success', 'Bedroom updated.');
    }

    public function destroy(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('update', $site);
        abort_unless($room->site_id === $site->id, 404);

        $this->physicalRooms->deactivateResidentialRoom($site, $room, $request->user());

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

        $newClientId = isset($validated['client_id']) ? (int) $validated['client_id'] : null;
        $this->placements->assignRoom($site, $room, $newClientId, $request->user(), $validated);

        return back()->with(
            'success',
            $newClientId ? 'Client assigned to bedroom.' : 'Bedroom unassigned.'
        );
    }

    /**
     * Attach a site asset to a room. Assets can live in any room — including
     * communal spaces (kitchen TV, lounge sofa) — so the only constraint is
     * that the asset belongs to the same site as the room.
     */
    public function attachAsset(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('update', $site);
        abort_unless($room->site_id === $site->id, 404);

        $validated = $request->validate([
            'asset_id' => ['required', 'integer', 'exists:assets,id'],
        ]);

        $asset = Asset::query()->findOrFail($validated['asset_id']);
        $this->physicalRooms->placeAsset($site, $room, $asset);

        return back()->with('success', 'Asset attached to room.');
    }

    public function detachAsset(Request $request, Site $site, SiteHouseRoom $room, Asset $asset)
    {
        $this->authorize('update', $site);
        abort_unless($room->site_id === $site->id, 404);
        $this->physicalRooms->removeAsset($site, $room, $asset);

        return back()->with('success', 'Asset removed from bedroom.');
    }

    /**
     * Persist a new sort order for rooms after a drag-and-drop. Accepts an
     * ordered array of room IDs; rooms not in the array keep their existing
     * sort_order.
     */
    public function reorder(Request $request, Site $site)
    {
        $this->authorize('update', $site);

        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'distinct', 'exists:site_house_rooms,id'],
        ]);

        $this->physicalRooms->reorderResidentialRooms($site, $validated['ordered_ids']);

        return back()->with('success', 'Bedroom order updated.');
    }

    /**
     * Render the printable door-card view for a single bedroom. Surfaces
     * the occupant + key worker + safeguarding/risk flags in a layout
     * intended to be printed and tucked into the door pocket.
     */
    public function doorCard(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('view', $site);
        abort_unless($room->site_id === $site->id, 404);

        $room->load([
            'assignedClient' => fn ($q) => $q->with([
                'keyWorker:id,name',
                'emergencyContacts',
                'nextOfKins',
                'medicalProfile',
            ]),
        ]);

        return view('sites.rooms.door-card', [
            'site' => $site,
            'room' => $room,
            'client' => $room->assignedClient,
            'generatedAt' => now(),
            'generatedBy' => $request->user(),
        ]);
    }

    /**
     * Restore a soft-deactivated room (sets is_active back to true).
     */
    public function restore(Request $request, Site $site, SiteHouseRoom $room)
    {
        $this->authorize('update', $site);
        abort_unless($room->site_id === $site->id, 404);

        $this->physicalRooms->restoreResidentialRoom($site, $room);

        return back()->with('success', 'Bedroom restored.');
    }
}
