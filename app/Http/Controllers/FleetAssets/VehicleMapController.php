<?php

namespace App\Http\Controllers\FleetAssets;

use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Sites\SiteGeocodingController;
use App\Models\Asset;
use App\Models\User;
use App\Services\Fleet\VehicleGeofenceService;
use App\Services\Fleet\VehicleLocationService;
use App\Services\Fleet\VehicleTelemetryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The vehicle profile's Map tab: Location & geofences and Vehicle telemetry.
 * Every endpoint re-resolves the vehicle in the viewer's scope, so a foreign
 * or missing vehicle answers 404. Geofence writes need fleet.manage or
 * assets.geofences.manage and never start monitoring.
 */
class VehicleMapController extends Controller
{
    public function __construct(
        private readonly VehicleLocationService $location,
        private readonly VehicleGeofenceService $geofences,
        private readonly VehicleTelemetryPresenter $telemetry,
        private readonly SecurityDevicesAccessService $vehicles,
    ) {}

    public function location(Request $request, Asset $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->geofences->vehicle($viewer, (int) $asset->getKey());

        return $this->private(response()->json($this->location->present($viewer, $vehicle)));
    }

    public function trail(Request $request, Asset $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->geofences->vehicle($viewer, (int) $asset->getKey());
        $data = $request->validate(['trip' => ['nullable', 'integer', 'min:1']]);

        return $this->private(response()->json(
            $this->location->trail($viewer, $vehicle, isset($data['trip']) ? (int) $data['trip'] : null),
        ));
    }

    public function catalogue(Request $request, Asset $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->geofences->vehicle($viewer, (int) $asset->getKey());
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return $this->private(response()->json($this->geofences->catalogue($viewer, $vehicle, (string) ($data['q'] ?? ''))));
    }

    /**
     * Places to centre the boundary editor: the viewer's Sites, and street
     * addresses from the shared geocoder. Only the typed text is sent to the
     * address provider, never a vehicle or tracker position.
     */
    public function places(Request $request, Asset $asset, SiteGeocodingController $geocoder): JsonResponse
    {
        $viewer = $this->actor($request);
        abort_unless($this->geofences->canManage($viewer), 403);
        $vehicle = $this->geofences->vehicle($viewer, (int) $asset->getKey());
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200']]);
        $query = trim((string) ($data['q'] ?? ''));
        $places = $this->location->sitePlaces($viewer, $vehicle, $query);
        $addresses = 'not_requested';
        if (mb_strlen($query) >= 3) {
            try {
                $results = $geocoder->search($request, true)->getData(true)['results'] ?? [];
                $addresses = 'ok';
                foreach (array_slice(is_array($results) ? $results : [], 0, 6) as $index => $result) {
                    if (! is_numeric($result['lat'] ?? null) || ! is_numeric($result['lng'] ?? null)) {
                        continue;
                    }
                    $places[] = [
                        'key' => 'address:'.$index,
                        'kind' => 'address',
                        'label' => (string) (($result['address_line_1'] ?? null) ?: ($result['display_name'] ?? 'Address')),
                        'detail' => (string) ($result['display_name'] ?? ''),
                        'lat' => round((float) $result['lat'], 7),
                        'lng' => round((float) $result['lng'], 7),
                    ];
                }
            } catch (\RuntimeException) {
                $addresses = 'unavailable';
            }
        }

        return $this->private(response()->json(['places' => $places, 'addresses' => $addresses]));
    }

    public function selection(Request $request, Asset $asset): JsonResponse
    {
        $linked = $this->geofences->select($this->actor($request), (int) $asset->getKey(),
            $request->only(['keep_assignment_ids', 'add_geofence_ids', 'expected_version']));

        return $this->private(response()->json([
            'geofences' => $linked,
            'message' => 'Geofence selection saved. Linked boundaries are inactive; no monitoring or alerts started.',
        ]));
    }

    public function storeGeofence(Request $request, Asset $asset): JsonResponse
    {
        $actor = $this->actor($request);
        $assignment = $this->geofences->create($actor, (int) $asset->getKey(),
            $request->only(['source', 'geofence_id', 'geometry_hash', 'geometry', 'label', 'purpose', 'response_proposal', 'schedule']),
            $this->key($request));
        $vehicle = $this->geofences->vehicle($actor, (int) $asset->getKey());

        return $this->private(response()->json([
            'assignment_id' => (int) $assignment->id,
            'geofences' => $this->geofences->linked($actor, $vehicle),
            'message' => 'Inactive assignment saved. No monitoring or alerts are active.',
        ]));
    }

    public function updateGeofence(Request $request, Asset $asset, int $assignment): JsonResponse
    {
        $actor = $this->actor($request);
        $saved = $this->geofences->update($actor, (int) $asset->getKey(), $assignment,
            $request->only(['source', 'expected_version', 'geofence_id', 'geometry_hash', 'source_change_reviewed', 'geometry',
                'label', 'purpose', 'response_proposal', 'schedule']));
        $vehicle = $this->geofences->vehicle($actor, (int) $asset->getKey());

        return $this->private(response()->json([
            'assignment_id' => (int) $saved->id,
            'lock_version' => (int) $saved->lock_version,
            'geofences' => $this->geofences->linked($actor, $vehicle),
            'message' => 'Geofence assignment saved. It stays inactive.',
        ]));
    }

    public function telemetry(Request $request, Asset $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        abort_unless($viewer->canDo('fleet.viewAny'), 403);
        $vehicle = $this->vehicles->assignableVehicle($viewer, (int) $asset->getKey()) ?? abort(404);
        abort_unless($this->telemetry->canView($viewer, $vehicle), 403);

        return $this->private(response()->json($this->telemetry->present($viewer, $vehicle)));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function key(Request $request): string
    {
        return mb_substr((string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), 0, 100);
    }

    /** Positions and device state are personal operational data: never cache them. */
    private function private(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
