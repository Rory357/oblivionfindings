<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\Fleet\Data\VehicleReadinessContext;
use App\Services\Fleet\VehicleComplianceService;
use App\Services\Fleet\VehicleOdometerService;
use App\Services\Fleet\VehicleReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleEvidenceController extends Controller
{
    public function __construct(
        private readonly VehicleComplianceService $compliance,
        private readonly VehicleOdometerService $odometer,
        private readonly VehicleReadinessService $readiness,
    ) {}

    public function compliance(Request $request, Asset $asset, string $kind): JsonResponse
    {
        $actor = $request->user(); abort_unless($actor instanceof User, 403);
        // Shape validation belongs inside the command after the canonical
        // scoped Asset is locked. The route model is only an ID carrier.
        $expected = $request->input('expected_current_version_id');
        $version = $this->compliance->record($actor, (int) $asset->getKey(), $kind, $request->except('expected_current_version_id', 'request_key'),
            (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''),
            $expected);
        $canonical = $version->record->asset;
        return response()->json(['version' => ['id' => $version->id, 'version' => $version->version, 'record_id' => $version->record_id],
            'readiness' => $this->readiness->assess($canonical, new VehicleReadinessContext)->toArray()]);
    }

    public function odometer(Request $request, Asset $asset): JsonResponse
    {
        $actor = $request->user(); abort_unless($actor instanceof User, 403);
        $observation = $this->odometer->recordManual($actor, (int) $asset->getKey(), $request->except('request_key'),
            (string) ($request->input('request_key') ?: $request->header('Idempotency-Key') ?: ''));
        return response()->json(['observation' => ['id' => $observation->id, 'value_km' => (float) $observation->value_km,
            'observed_at' => $observation->observed_at?->toISOString()],
            'readiness' => $this->readiness->assess($observation->asset, new VehicleReadinessContext)->toArray()]);
    }
}
