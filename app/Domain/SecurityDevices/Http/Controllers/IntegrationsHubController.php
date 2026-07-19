<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Http\Controllers\Concerns\ResolvesDeviceTenant;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Integration\IntegrationTenantSecret;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class IntegrationsHubController extends Controller
{
    use ResolvesDeviceTenant;

    /**
     * Catalog of providers the module plans to support.
     * Kept in code (not DB) so the hub can surface providers that have no credentials yet.
     * Order matters — drives display sequence.
     *
     * @var array<int, array<string, mixed>>
     */
    private const PROVIDER_CATALOG = [
        [
            'slug' => 'unifi',
            'name' => 'UniFi',
            'vendor' => 'Ubiquiti',
            'summary' => 'Network, Protect (CCTV), Access, and AI Ports across managed sites.',
            'implementation_status' => 'live',
            'capabilities' => ['network', 'cctv', 'access_control', 'device_health', 'event_stream'],
            'device_scope' => ['cameras', 'doors', 'access points', 'switches', 'gateways'],
            'docs_href' => '/security-devices/integrations/unifi',
        ],
        [
            'slug' => 'queclink',
            'name' => 'Queclink',
            'vendor' => 'Queclink Wireless',
            'summary' => 'Cellular GPS trackers for vehicles, assets, and personal / client use.',
            'implementation_status' => 'live',
            'capabilities' => ['tracking', 'telemetry', 'device_health', 'event_stream'],
            'device_scope' => ['vehicle trackers', 'personal trackers', 'asset trackers'],
            'docs_href' => '/security-devices/integrations/queclink',
        ],
        [
            'slug' => 'milesight',
            'name' => 'Milesight',
            'vendor' => 'Milesight IoT',
            'summary' => 'LoRaWAN sensors and gateways: environmental, occupancy, leak, and resident-support IoT.',
            'implementation_status' => 'live',
            'capabilities' => ['iot', 'environmental', 'healthcare_sensors', 'gateway_management', 'event_stream'],
            'device_scope' => ['bed sensors', 'fall sensors', 'door contacts', 'temp / humidity', 'leak detectors', 'air quality'],
            'docs_href' => '/security-devices/integrations/milesight',
        ],
    ];

    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('securityDevices.integrations.view'), 403);

        $tenantId = $this->resolveDeviceTenantId($user);

        // Pull real connection state. Keyed by provider slug for fast lookup.
        $secrets = IntegrationTenantSecret::query()
            ->forTenant($tenantId)
            ->get()
            ->keyBy('provider');

        $last24h = now()->subDay();

        // Per-provider device and event counts — one grouped query each.
        $deviceCountsByProvider = Device::query()
            ->forTenant($tenantId)
            ->whereNotNull('provider')
            ->selectRaw('provider, count(*) as count')
            ->groupBy('provider')
            ->pluck('count', 'provider')
            ->toArray();

        $eventCountsByProvider = DeviceEvent::query()
            ->forTenant($tenantId)
            ->whereNotNull('source')
            ->where('occurred_at', '>=', $last24h)
            ->selectRaw('source, count(*) as count')
            ->groupBy('source')
            ->pluck('count', 'source')
            ->toArray();

        $providers = [];
        foreach (self::PROVIDER_CATALOG as $entry) {
            $slug = $entry['slug'];
            $secret = $secrets->get($slug);

            $providers[] = array_merge($entry, [
                'connection_status' => $secret?->status ?? 'not_configured',
                'connected' => $secret?->status === IntegrationTenantSecret::STATUS_CONNECTED,
                'last_tested_at' => $secret?->last_tested_at?->toISOString(),
                'last_synced_at' => $secret?->last_synced_at?->toISOString(),
                'secret_last4' => $secret?->secret_last4,
                'device_count' => $deviceCountsByProvider[$slug] ?? 0,
                'events_24h' => $eventCountsByProvider[$slug] ?? 0,
            ]);
        }

        // Roll-ups for the hub's summary strip.
        $stats = [
            'providers_total' => count(self::PROVIDER_CATALOG),
            'providers_live' => count(array_filter(self::PROVIDER_CATALOG, fn ($p) => $p['implementation_status'] === 'live')),
            'providers_connected' => $secrets->where('status', IntegrationTenantSecret::STATUS_CONNECTED)->count(),
            'providers_errored' => $secrets->where('status', IntegrationTenantSecret::STATUS_ERROR)->count(),
            'imported_devices' => array_sum($deviceCountsByProvider),
            'events_24h' => array_sum($eventCountsByProvider),
        ];

        $canManage = $user->canDo('securityDevices.integrations.manage');

        return Inertia::render('security-devices/integrations', [
            'providers' => $providers,
            'stats' => $stats,
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }
}
