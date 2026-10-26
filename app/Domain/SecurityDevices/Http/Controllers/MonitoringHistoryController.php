<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\MonitoringHistoryService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MonitoringHistoryController extends Controller
{
    public function __invoke(
        Request $request,
        Monitor $monitor,
        SecurityDevicesAccessService $access,
        CanonicalDeviceSiteResolver $siteResolver,
        MonitoringHistoryService $historyService,
    ): JsonResponse {
        $viewer = $request->user();
        abort_unless(
            $viewer !== null
            && ($viewer->canDo('securityDevices.events.view') || $viewer->canDo('securityDevices.devices.view')),
            403,
        );

        $validated = $request->validate([
            'range' => ['nullable', 'string', Rule::in(array_keys(MonitoringHistoryService::RANGES))],
        ]);

        $device = $access->visibleDevices($viewer)->whereKey($monitor->device_id)->first();
        if (! $device) {
            abort(404);
        }

        try {
            $siteId = $siteResolver->resolveForContext((int) $device->id);
        } catch (\Throwable) {
            $siteId = null;
        }
        if ($siteId !== null && ! $access->canViewAllSites($viewer)) {
            $allowedSiteIds = $access->accessibleSiteIds($viewer);
            abort_unless(in_array($siteId, $allowedSiteIds, true), 403);
        }

        return response()
            ->json([
                'history' => $historyService->historyForMonitor(
                    $monitor,
                    (string) ($validated['range'] ?? '24h'),
                ),
            ])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
