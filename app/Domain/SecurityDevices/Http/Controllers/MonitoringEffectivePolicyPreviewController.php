<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\EffectiveMonitoringPolicyResolver;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class MonitoringEffectivePolicyPreviewController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly CanonicalDeviceSiteResolver $siteResolver,
        private readonly EffectiveMonitoringPolicyResolver $resolver,
    ) {}

    public function show(Request $request, Monitor $monitor): JsonResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor instanceof User
            && ($actor->canDo('securityDevices.monitoring.manage') || $actor->canDo('securityDevices.events.view')),
            403,
        );

        $visible = Monitor::query()
            ->whereKey($monitor->id)
            ->whereIn('device_id', $this->access->visibleDevices($actor)->select('devices.id'))
            ->with(['device', 'profile'])
            ->firstOrFail();

        try {
            $siteId = $this->siteResolver->resolve((int) $visible->device_id);
        } catch (Throwable) {
            abort(422, 'The monitor device does not have a single active Site assignment.');
        }

        $this->access->assertCanViewSite($actor, $siteId);

        return response()
            ->json(['preview' => $this->resolver->previewForMonitor($visible, $siteId)])
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
