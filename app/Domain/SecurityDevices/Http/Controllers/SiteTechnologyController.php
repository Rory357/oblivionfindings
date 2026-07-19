<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class SiteTechnologyController extends Controller
{
    use MapsDevicesForList;

    public function index(Request $request, DeviceRegistryService $registry): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $tenantId = (int) ($user->organization_id ?: 1);
        $sites = Site::query()
            ->where('tenant_id', $tenantId)
            ->where('archived', false)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'city', 'is_active'])
            ->map(function (Site $site) use ($registry, $tenantId): array {
                $devices = $registry->forSite($tenantId, $site->id);

                return [
                    'id' => $site->id,
                    'name' => $site->name,
                    'type' => $site->type,
                    'city' => $site->city,
                    'is_active' => (bool) $site->is_active,
                    'device_count' => (clone $devices)->count(),
                    'attention_count' => (clone $devices)->needingAttention()->count(),
                    'href' => "/security-devices/sites/{$site->id}",
                ];
            })
            ->values();

        return Inertia::render('security-devices/sites/index', [
            'sites' => $sites,
            'summary' => [
                'total' => $sites->count(),
                'with_devices' => $sites->where('device_count', '>', 0)->count(),
                'requiring_attention' => $sites->where('attention_count', '>', 0)->count(),
            ],
        ]);
    }

    public function show(Request $request, Site $site, DeviceRegistryService $registry): Response
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $tenantId = (int) ($user->organization_id ?: 1);
        abort_unless((int) $site->tenant_id === $tenantId && ! $site->archived, 404);

        $deviceQuery = $registry->forSite($tenantId, $site->id);
        $devices = (clone $deviceQuery)
            ->with(['assignments' => fn ($query) => $query->active()])
            ->orderBy('name')
            ->get()
            ->map(fn (Device $device) => $this->mapDeviceForList($device))
            ->values();

        return Inertia::render('security-devices/sites/show', [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'type' => $site->type,
                'city' => $site->city,
                'address' => collect([
                    $site->address_line_1,
                    $site->suburb,
                    $site->city,
                ])->filter()->implode(', '),
                'is_active' => (bool) $site->is_active,
            ],
            'devices' => $devices,
            'summary' => [
                'devices' => $devices->count(),
                'attention' => $devices->whereIn('health_status', ['warning', 'critical', 'unknown'])->count(),
                'offline' => $devices->where('status', 'offline')->count(),
            ],
        ]);
    }
}
