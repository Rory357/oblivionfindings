<?php

namespace App\Http\Controllers\Portal;

use App\Domain\SecurityDevices\Services\DeviceRegistryService;
use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Services\Integration\IntegrationEventHistoryService;
use App\Services\Portal\PortalClientSectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PortalLocationController extends Controller
{
    public function __construct(
        private readonly PortalClientSectionAccess $sectionAccess,
    ) {}

    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $trackingConsent = $this->sectionAccess->activeLocationTrackingConsent($client);
        abort_unless($trackingConsent, 403);

        $tenantId = (int) ($client->organization_id ?? $client->site?->tenant_id ?? 1);

        // Canonical device lookup — active tracking device assigned to this client.
        $device = app(DeviceRegistryService::class)
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        $currentLocation = null;
        $trackerInfo = null;

        if ($device) {
            $meta = $device->meta ?? [];
            $lat = $device->latitude ?? $meta['lat'] ?? $meta['latitude'] ?? null;
            $lng = $device->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? null;

            $trackerInfo = [
                'id' => $device->id,
                'device_uid' => $device->device_uid,
                'name' => $device->name,
                'serial' => $device->serial_number,
                'status' => $device->status?->value ?? 'unknown',
                'last_seen_at' => $device->last_seen_at?->toISOString(),
                'battery' => $device->battery_level,
                'detail_url' => "/security-devices/devices/{$device->id}",
            ];

            if ($lat !== null && $lng !== null) {
                $currentLocation = [
                    'lat' => (float) $lat,
                    'lng' => (float) $lng,
                    'speed' => $meta['speed'] ?? null,
                    'heading' => $meta['heading'] ?? null,
                    'accuracy' => $meta['accuracy'] ?? null,
                ];
            }
        }

        // Load geofences for the client's site.
        $geofences = [];
        try {
            if ($client->site_id && Schema::hasTable('asset_geofences')) {
                $siteId = $client->site_id;
                $geofences = AssetGeofence::query()
                    ->forOrganization($tenantId)
                    ->where('is_active', true)
                    ->where('site_id', $siteId)
                    ->whereIn('scope', ['house', 'resident'])
                    ->get()
                    ->map(function ($gf) {
                        $shape = $gf->shape ?? [];
                        $result = [
                            'id' => (string) $gf->id,
                            'name' => $gf->name,
                            'type' => $gf->type ?? 'circle',
                            'color' => $shape['color'] ?? '#8b5cf6',
                        ];

                        if ($gf->type === 'circle') {
                            $result['center'] = [
                                'lat' => $shape['lat'] ?? $shape['latitude'] ?? 0,
                                'lng' => $shape['lng'] ?? $shape['lon'] ?? $shape['longitude'] ?? 0,
                            ];
                            $result['radius_m'] = $shape['radius_m'] ?? $shape['radius'] ?? 100;
                        } elseif ($gf->type === 'polygon') {
                            $points = $shape['coordinates'] ?? $shape['points'] ?? [];
                            $result['coordinates'] = collect($points)->map(fn ($p) => [
                                'lat' => $p['lat'] ?? $p['latitude'] ?? 0,
                                'lng' => $p['lng'] ?? $p['lon'] ?? $p['longitude'] ?? 0,
                            ])->toArray();
                        }

                        return $result;
                    })
                    ->toArray();
            }
        } catch (\Throwable $e) {
            $geofences = [];
        }

        return inertia('portal/location', [
            'client' => [
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'preferred_name' => $client->preferred_name,
                'profile_photo_url' => $client->profile_photo_url,
                'house' => $client->site?->name ?? 'Unknown',
            ],
            'tracker' => $trackerInfo,
            'currentLocation' => $currentLocation,
            'trackingConsent' => $trackingConsent ? [
                // The portal UI treats any active consent as "active" / "granted",
                // so normalize the model's stored "given" status into that vocabulary.
                'status' => 'active',
                'given_at' => optional($trackingConsent->given_at)->toISOString(),
                'expires_at' => optional($trackingConsent->expires_at)->toISOString(),
            ] : null,
            'geofences' => $geofences,
        ]);
    }

    public function history(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        abort_unless($this->sectionAccess->activeLocationTrackingConsent($client), 403);

        $tenantId = (int) ($client->organization_id ?? $client->site?->tenant_id ?? 1);

        // Canonical device lookup.
        $device = app(DeviceRegistryService::class)
            ->forClient($tenantId, $client->id)
            ->where('domain', 'tracking')
            ->first();

        $locations = app(IntegrationEventHistoryService::class)
            ->forDevice($device, $request->only(['date_from', 'date_to']));

        return response()->json([
            'locations' => $locations,
        ]);
    }
}
