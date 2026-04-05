<?php

namespace App\Http\Controllers;

use App\Services\Fleet\FleetTelemetryIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AssetTelemetryIngestController extends Controller
{
    public function store(
        Request $request,
        string $vendor,
        FleetTelemetryIngestService $ingestService
    ) {
        $user = $request->user();
        $token = $request->header('X-Telemetry-Token');
        $expectedToken = config('services.telemetry.ingest_token');

        $authorized = $user && $user->canDo('assets.telemetry.ingest');
        if (!$authorized && $expectedToken && $token === $expectedToken) {
            $authorized = true;
        }

        if (!$authorized) {
            Log::warning('Telemetry ingest unauthorized attempt', ['ip' => $request->ip(), 'vendor' => $vendor]);
            abort(403);
        }

        $result = $ingestService->ingest($vendor, $request->all());

        return response()->json(
            ['ok' => $result['ok'], 'id' => $result['id'] ?? null, 'duplicate' => $result['duplicate'] ?? false, 'error' => $result['error'] ?? null],
            $result['status'] ?? 200
        );
    }
}
