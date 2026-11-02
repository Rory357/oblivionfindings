<?php

namespace App\Domain\SecurityDevices\NetworkChecks\Http;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\NetworkChecks\NetworkChecks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Pop-up 97, the hub's side: its poll over the authenticated collector link. What it found goes in;
 * the next read-only checks come back, each signed by Oblivion.
 */
final class NetworkCheckCollectorController extends Controller
{
    public function work(Request $request, NetworkChecks $checks): JsonResponse
    {
        $collector = $request->attributes->get('monitoring_collector');
        abort_unless($collector instanceof MonitoringCollector, 401, 'unavailable');
        if (strlen((string) $request->getContent()) > 1_048_576) {
            return response()->json(['message' => 'too_large'], 413)->header('Cache-Control', 'no-store');
        }
        $report = $request->json('report');
        try {
            $work = $checks->hubWork($collector, is_array($report) ? $report : []);
        } catch (Throwable) {
            return response()->json(['message' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([...$work, 'server_now' => now()->utc()->format(DATE_ATOM)])->header('Cache-Control', 'no-store');
    }
}
