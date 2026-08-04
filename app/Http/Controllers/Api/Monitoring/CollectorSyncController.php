<?php

namespace App\Http\Controllers\Api\Monitoring;

use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Services\CollectorConfigurationService;
use App\Domain\Monitoring\Services\CollectorHealthService;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CollectorSyncController extends Controller
{
    public function configuration(Request $request, CollectorConfigurationService $configuration): JsonResponse
    {
        $collector = $this->collector($request);
        $afterSequence = $request->input('after_sequence');
        if (! is_int($afterSequence)) {
            return response()->json(['message' => 'Collector request is invalid.'], 422);
        }
        try {
            $envelope = $configuration->signedEnvelope($collector, $afterSequence);
        } catch (DomainException) {
            return response()->json(['message' => 'Collector configuration is unavailable.'], 409);
        }

        return response()->json(['envelope' => $envelope]);
    }

    public function observations(Request $request, CollectorIngestService $ingest): JsonResponse
    {
        $items = $request->input('items');
        if (! is_array($items)) {
            return response()->json(['message' => 'Collector request is invalid.'], 422);
        }
        try {
            $acknowledgement = $ingest->ingest($this->collector($request), $items);
        } catch (DomainException) {
            return response()->json(['message' => 'Collector upload is invalid.'], 422);
        }

        return response()->json($acknowledgement);
    }

    public function heartbeat(Request $request, CollectorHealthService $health): JsonResponse
    {
        $status = $request->input('status');
        if (! is_array($status)) {
            return response()->json(['message' => 'Collector request is invalid.'], 422);
        }
        try {
            $collector = $health->recordHeartbeat($this->collector($request), $status);
        } catch (DomainException) {
            return response()->json(['message' => 'Collector heartbeat is invalid.'], 422);
        }

        return response()->json([
            'status' => $collector->status,
            'acknowledged_source_sequence' => (int) $collector->acknowledged_source_sequence,
            'gap_count' => (int) $collector->gap_count,
        ]);
    }

    private function collector(Request $request): MonitoringCollector
    {
        $collector = $request->attributes->get('monitoring_collector');
        if (! $collector instanceof MonitoringCollector) {
            abort(401, 'Collector authentication failed.');
        }

        return $collector;
    }
}
