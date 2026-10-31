<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\ConfigurationSnapshotComparisonService;
use App\Domain\SecurityDevices\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConfigurationSnapshotComparisonController extends Controller
{
    public function __invoke(
        Request $request,
        Device $device,
        ConfigurationSnapshot $snapshot,
        ConfigurationSnapshotComparisonService $comparison,
        ?ConfigurationSnapshot $baseline = null,
    ): JsonResponse {
        $result = $comparison->compare(
            device: $device,
            target: $snapshot,
            baseline: $baseline,
            actor: $request->user(),
        );

        return response()->json($result, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
