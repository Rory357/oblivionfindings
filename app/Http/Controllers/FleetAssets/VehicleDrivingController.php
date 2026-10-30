<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Fleet\DrivingScorePolicyStore;
use App\Services\Fleet\VehicleDrivingInsightsService;
use App\Services\Fleet\VehicleDrivingReviewService;
use App\Services\Fleet\VehicleSpeedLimitService;
use App\Services\Fleet\VehicleTripHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PKG-02B vehicle profile › Map › Driving insights: analytics, event
 * reviews, the versioned scoring policy and manual speed limits. Reads
 * resolve the vehicle through the trip history rules (vehicle profile and
 * trip Site), so a foreign or missing vehicle answers 404. Driver-attributed
 * data is personal: responses are never cached.
 */
class VehicleDrivingController extends Controller
{
    public function __construct(
        private readonly VehicleTripHistoryService $trips,
        private readonly VehicleDrivingInsightsService $insights,
        private readonly VehicleDrivingReviewService $reviews,
        private readonly DrivingScorePolicyStore $policies,
        private readonly VehicleSpeedLimitService $limits,
    ) {}

    public function show(Request $request, int $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->trips->vehicle($viewer, $asset);
        $data = $request->validate([
            'period' => ['nullable', 'in:'.implode(',', array_keys(VehicleDrivingInsightsService::PERIODS))],
            'driver' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->private(response()->json($this->insights->present(
            $viewer, $vehicle, (string) ($data['period'] ?? 'week'), (string) ($data['driver'] ?? 'all'),
        )));
    }

    public function reviews(Request $request, int $asset): JsonResponse
    {
        $viewer = $this->actor($request);
        $vehicle = $this->trips->vehicle($viewer, $asset);
        $data = $request->validate(['trip' => ['nullable', 'integer', 'min:1']]);

        return $this->private(response()->json($this->insights->reviews(
            $viewer, $vehicle, isset($data['trip']) ? (int) $data['trip'] : null,
        )));
    }

    public function review(Request $request, int $asset, int $trip): JsonResponse
    {
        $result = $this->reviews->record($this->actor($request), $asset, $trip,
            $request->only(['event_key', 'outcome', 'reason', 'review_owner_user_id', 'confirmed', 'expected_version']),
            $this->key($request));

        return $this->private(response()->json([
            'trip' => $result,
            'message' => 'Event review recorded and the trip score recalculated. The recorded telemetry is unchanged.',
        ]));
    }

    public function publishPolicy(Request $request, int $asset): JsonResponse
    {
        $policy = $this->policies->publish($this->actor($request), $asset, $request->only([
            'braking', 'acceleration', 'overspeed', 'idle', 'coverage', 'trips', 'distance', 'reason', 'confirmed',
            'expected_version',
        ]), $this->key($request));

        return $this->private(response()->json([
            'policy' => $this->policies->describe(),
            'message' => 'Scoring policy version '.$policy->version.' published. Earlier versions stay in its history.',
        ]));
    }

    public function speedLimits(Request $request, int $asset): JsonResponse
    {
        $viewer = $this->actor($request);

        return $this->private(response()->json($this->limits->present($viewer, $this->trips->vehicle($viewer, $asset))));
    }

    public function proposeLimit(Request $request, int $asset): JsonResponse
    {
        $limit = $this->limits->propose($this->actor($request), $asset, $request->only([
            'road_segment', 'direction', 'limit_kph', 'effective_from_local', 'effective_from_offset',
            'expires_at_local', 'expires_at_offset', 'reason',
        ]), $this->key($request));

        return $this->private(response()->json([
            'limit' => ['id' => (int) $limit->id, 'status' => $limit->status, 'lock_version' => (int) $limit->lock_version],
            'message' => 'Manual speed limit submitted. It does not apply until someone else approves it with evidence.',
        ]));
    }

    public function reviewLimit(Request $request, int $asset, int $limit, string $action): JsonResponse
    {
        $saved = $this->limits->review($this->actor($request), $asset, $limit, $action,
            $request->only(['reason', 'confirmed', 'expected_version']), $this->key($request));

        return $this->private(response()->json([
            'limit' => ['id' => (int) $saved->id, 'status' => $saved->status, 'lock_version' => (int) $saved->lock_version],
            'message' => $action === 'approve'
                ? 'Manual speed limit approved. Evaluations use it between its start and expiry.'
                : 'Manual speed limit retired. Earlier evaluations keep the evidence they used.',
        ]));
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function key(Request $request): string
    {
        return mb_substr((string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''), 0, 90);
    }

    /** Driver-attributed driving data is personal: never cache it. */
    private function private(JsonResponse $response): JsonResponse
    {
        return $response->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
