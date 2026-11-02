<?php

namespace App\Domain\SecurityDevices\Access\Http;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Access\AccessJobs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * S&D Phase H (H1): the hub poll for UniFi Access changes, over its authenticated collector link.
 * It reports what it did and gets the next changes, each signed by Oblivion.
 */
final class AccessJobsCollectorController extends Controller
{
    public function work(Request $request, AccessJobs $jobs): JsonResponse
    {
        $collector = $request->attributes->get('monitoring_collector');
        abort_unless($collector instanceof MonitoringCollector, 401, 'unavailable');
        if (strlen((string) $request->getContent()) > 1_048_576) {
            return response()->json(['message' => 'too_large'], 413)->header('Cache-Control', 'no-store');
        }
        $report = $request->json('report');
        try {
            $work = $jobs->hubWork($collector, is_array($report) ? $report : []);
        } catch (Throwable) {
            return response()->json(['message' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([...$work, 'server_now' => now()->utc()->format(DATE_ATOM)])->header('Cache-Control', 'no-store');
    }
}
