<?php

namespace App\Domain\SecurityDevices\Terminal\Http;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Terminal\TerminalSessions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * S&D Phase G (D5): the hub's terminal poll, over its authenticated collector link. It reports what
 * its terminals sent back and gets lines to type, terminals to open (each signed) and to close.
 */
final class TerminalCollectorController extends Controller
{
    public function work(Request $request, TerminalSessions $terminals): JsonResponse
    {
        $collector = $request->attributes->get('monitoring_collector');
        abort_unless($collector instanceof MonitoringCollector, 401, 'unavailable');
        if (strlen((string) $request->getContent()) > 1_048_576) {
            return response()->json(['message' => 'too_large'], 413)->header('Cache-Control', 'no-store');
        }
        $report = $request->json('report');
        try {
            $work = $terminals->hubWork($collector, is_array($report) ? $report : []);
        } catch (Throwable) {
            return response()->json(['message' => 'unavailable'], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([...$work, 'server_now' => now()->utc()->format(DATE_ATOM)])->header('Cache-Control', 'no-store');
    }
}
