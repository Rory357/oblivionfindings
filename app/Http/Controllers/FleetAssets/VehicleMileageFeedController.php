<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FleetVehicleMileageFeed;
use App\Models\User;
use App\Services\Fleet\VehicleMileageFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reconcile a vehicle's dashboard reading with its tracker, or pause automatic planning. */
class VehicleMileageFeedController extends Controller
{
    public function __construct(private readonly VehicleMileageFeedService $feed) {}

    public function reconcile(Request $request, Asset $asset): JsonResponse
    {
        $feed = $this->feed->reconcile($this->actor($request), (int) $asset->getKey(),
            $request->only(['value_km', 'reason', 'automatic', 'tolerance_km', 'confirmed']), $this->key($request));

        return response()->json(['feed' => $this->result($feed), 'observation_id' => $feed->baseline_observation_id,
            'message' => $feed->automatic
                ? 'Dashboard reading kept and the tracker reconciled. Automatic planning is on.'
                : 'Dashboard reading kept and the tracker reconciled. Planning uses recorded readings.']);
    }

    public function pause(Request $request, Asset $asset): JsonResponse
    {
        $feed = $this->feed->pause($this->actor($request), (int) $asset->getKey(), (string) $request->input('reason', ''),
            (int) $request->input('expected_version'), $this->key($request));

        return response()->json(['feed' => $this->result($feed),
            'message' => 'Automatic planning paused. Planning uses recorded readings.']);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function key(Request $request): string
    {
        return mb_substr((string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), 0, 90);
    }

    /** @return array<string,mixed> */
    private function result(FleetVehicleMileageFeed $feed): array
    {
        return ['id' => $feed->id, 'automatic' => $feed->automatic, 'tolerance_km' => $feed->tolerance_km,
            'lock_version' => $feed->lock_version];
    }
}
