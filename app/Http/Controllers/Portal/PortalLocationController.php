<?php

namespace App\Http\Controllers\Portal;

use App\Domain\SecurityDevices\Services\PersonalTrackingPrivacyService;
use App\Http\Controllers\Controller;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Services\Integration\IntegrationEventHistoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PortalLocationController extends Controller
{
    public function __construct(
        private readonly PersonalTrackingPrivacyService $trackingPrivacy,
        private readonly IntegrationEventHistoryService $history,
    ) {}

    public function index(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);

        $assignment = $this->trackingPrivacy->authorisedClientAssignment($client);
        abort_unless($assignment, 403);
        $trackingConsent = $assignment->consent;
        $device = $assignment->device;

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
            'privacyStatusUrl' => route(
                'portal.clients.location.privacy-status',
                ['client' => $client->id],
                false,
            ),
            'retentionDays' => (int) $assignment->retention_days,
        ])->toResponse($request)->withHeaders($this->privateLocationHeaders());
    }

    public function history(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($user->canAccessClientPortal($client), 403);
        $assignment = $this->trackingPrivacy->authorisedClientAssignment($client);
        abort_unless($assignment, 403);
        $locations = $this->history->forDevice(
            $assignment->device,
            $request->only(['date_from', 'date_to']),
            false,
            $assignment->retention_days,
        );

        return response()->json([
            'locations' => $locations,
        ])->withHeaders($this->privateLocationHeaders());
    }

    public function privacyStatus(Request $request, Client $client)
    {
        $user = $request->user();
        abort_unless($user && $user->canAccessClientPortal($client), 403);
        $assignment = $this->trackingPrivacy->authorisedClientAssignment($client);

        return response()->json([
            'active' => $assignment !== null,
            'checked_at' => now()->toISOString(),
            'retention_days' => $assignment?->retention_days,
            'export_allowed' => false,
        ])->withHeaders($this->privateLocationHeaders());
    }

    private function privateLocationHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Vary' => 'Cookie',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }
}
