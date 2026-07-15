<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\Client;
use App\Models\ControlRoom\Device;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ControlRoomMapController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.viewAny'), 403);
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = $this->alertBypassPermissions();
        $organizationId = is_numeric($user->organization_id) && (int) $user->organization_id > 0
            ? (int) $user->organization_id
            : null;
        $tenantSiteIds = $organizationId
            ? Site::query()
                ->where('tenant_id', $organizationId)
                ->pluck('id')
                ->map(fn ($siteId) => (int) $siteId)
                ->all()
            : [];
        $accessibleSiteIds = $siteAccess->canBypass($user, $bypassPermissions)
            ? $tenantSiteIds
            : array_values(array_intersect(
                $siteAccess->accessibleSiteIds($user, $bypassPermissions),
                $tenantSiteIds,
            ));
        $selectedSiteId = $request->filled('site_id') && $request->input('site_id') !== 'all'
            ? (int) $request->input('site_id')
            : null;

        if ($selectedSiteId) {
            abort_unless(
                in_array($selectedSiteId, $accessibleSiteIds, true),
                403,
                'You are not authorized to access the Control Room map for that site.',
            );
        }

        // Build device query - only those with coordinates.
        // Eager-load canonical device for enrichment.
        $deviceQuery = Device::query()
            ->with(['canonicalDevice' => function ($query) use ($organizationId): void {
                $query->select(['id', 'device_uid', 'domain', 'category', 'health_status']);
                if ($organizationId === null) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->where('tenant_id', $organizationId);
                }
            }])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
        $this->applyDeviceSiteScope($deviceQuery, $accessibleSiteIds, $organizationId);

        // Filter by site
        if ($selectedSiteId) {
            $this->applyDeviceSiteScope($deviceQuery, [$selectedSiteId], $organizationId);
        }

        // Filter by device type (vehicle_tracker or personal_tracker)
        if ($request->filled('type') && $request->input('type') !== 'all') {
            $deviceQuery->where('type', $request->input('type'));
        } else {
            // Default to only tracker types for the map view
            $deviceQuery->whereIn('type', [
                Device::TYPE_VEHICLE_TRACKER,
                Device::TYPE_PERSONAL_TRACKER,
            ]);
        }

        // Filter by status
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $deviceQuery->where('status', $request->input('status'));
        }

        // Alert-only filter: only show devices that have unresolved alerts
        if ($request->boolean('alert_only')) {
            $alertDeviceQuery = ControlRoomAlert::query()
                ->select('device_id')
                ->whereNotNull('device_id')
                ->actionable();
            $this->applyAlertSiteScope($alertDeviceQuery, $accessibleSiteIds, $organizationId);
            if ($selectedSiteId) {
                $this->applyAlertSiteScope($alertDeviceQuery, [$selectedSiteId], $organizationId);
            }
            $deviceQuery->whereIn('id', $alertDeviceQuery);
        }

        $devices = $deviceQuery->get();

        // Sites with coordinates for site markers
        $siteQuery = Site::query()
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereIn('id', $accessibleSiteIds);

        if ($selectedSiteId) {
            $siteQuery->whereKey($selectedSiteId);
        }

        $sites = $siteQuery->get();

        // Active geofences
        $geofenceQuery = AssetGeofence::query()
            ->where('is_active', true);
        $this->applyGeofenceSiteScope($geofenceQuery, $accessibleSiteIds);

        if ($selectedSiteId) {
            $this->applyGeofenceSiteScope($geofenceQuery, [$selectedSiteId]);
        }

        $geofences = $geofenceQuery->get();

        // Unresolved alerts with location context
        $alertQuery = ControlRoomAlert::query()
            ->actionable()
            ->where(function ($q) {
                $q->whereNotNull('device_id')
                    ->orWhereNotNull('site_id');
            })
            ->with([
                'device' => function ($query) use ($accessibleSiteIds, $organizationId, $selectedSiteId): void {
                    $this->applyDeviceSiteScope($query, $accessibleSiteIds, $organizationId);
                    if ($selectedSiteId) {
                        $this->applyDeviceSiteScope($query, [$selectedSiteId], $organizationId);
                    }
                    $query->select(['id', 'latitude', 'longitude', 'name']);
                },
                'asset' => function ($query) use ($accessibleSiteIds, $selectedSiteId): void {
                    $this->applyAssetSiteScope($query, $accessibleSiteIds);
                    if ($selectedSiteId) {
                        $this->applyAssetSiteScope($query, [$selectedSiteId]);
                    }
                    $query->select(['id', 'name']);
                },
            ]);
        $this->applyAlertSiteScope($alertQuery, $accessibleSiteIds, $organizationId);

        if ($selectedSiteId) {
            $this->applyAlertSiteScope($alertQuery, [$selectedSiteId], $organizationId);
        }

        $alerts = $alertQuery->latest('triggered_at')->limit(200)->get();

        // All sites for filter dropdown
        $allSites = Site::query()
            ->where('is_active', true)
            ->whereIn('id', $accessibleSiteIds)
            ->orderBy('name');
        $allSites = $allSites->get(['id', 'name']);

        // Stats
        $deviceStatsQuery = Device::query()
            ->whereIn('type', [Device::TYPE_VEHICLE_TRACKER, Device::TYPE_PERSONAL_TRACKER]);
        $this->applyDeviceSiteScope($deviceStatsQuery, $accessibleSiteIds, $organizationId);
        if ($selectedSiteId) {
            $this->applyDeviceSiteScope($deviceStatsQuery, [$selectedSiteId], $organizationId);
        }

        $activeAlertStatsQuery = ControlRoomAlert::query()->actionable();
        $this->applyAlertSiteScope($activeAlertStatsQuery, $accessibleSiteIds, $organizationId);
        if ($selectedSiteId) {
            $this->applyAlertSiteScope($activeAlertStatsQuery, [$selectedSiteId], $organizationId);
        }

        $stats = [
            'total_devices' => (clone $deviceStatsQuery)->count(),
            'online' => (clone $deviceStatsQuery)->where('status', 'online')->count(),
            'offline' => (clone $deviceStatsQuery)->where('status', 'offline')->count(),
            'active_alerts' => $activeAlertStatsQuery->count(),
        ];

        return Inertia::render('control-room/map', [
            'devices' => $devices->map(function (Device $d) {
                $canonical = $d->canonicalDevice;
                return [
                    'id' => $d->id,
                    'device_uid' => $d->device_uid,
                    'name' => $d->name,
                    'type' => $d->type,
                    'status' => $d->status,
                    'latitude' => (float) $d->latitude,
                    'longitude' => (float) $d->longitude,
                    'location_description' => $d->location_description,
                    'battery_level' => $d->battery_level,
                    'last_seen_at' => optional($d->last_seen_at)->toISOString(),
                    'vendor' => $d->vendor,
                    'model' => $d->getAttribute('model'),
                    'site_id' => $d->site_id,
                    'client_id' => $d->client_id,
                    'asset_id' => $d->asset_id,
                    // Canonical enrichment (safe fallback to null).
                    'canonical_id' => $canonical?->id,
                    'canonical_device_uid' => $canonical?->device_uid,
                    'canonical_health_status' => $canonical?->health_status?->value,
                    'canonical_detail_url' => $canonical ? "/security-devices/devices/{$canonical->id}" : null,
                ];
            })->values(),
            'sites' => $sites->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'address' => trim(implode(', ', array_filter([
                    $s->address_line_1,
                    $s->suburb,
                    $s->city,
                ]))),
                'latitude' => (float) $s->latitude,
                'longitude' => (float) $s->longitude,
            ])->values(),
            'geofences' => $geofences->map(fn (AssetGeofence $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'shape' => $g->shape,
                'breach_type' => $g->breach_type,
                'site_id' => $g->site_id,
            ])->values(),
            'alerts' => $alerts->map(fn (ControlRoomAlert $a) => [
                'id' => $a->id,
                'alert_type' => $a->alert_type,
                'severity' => $a->severity,
                'status' => $a->status,
                'triggered_at' => optional($a->triggered_at)->toISOString(),
                'device_id' => $a->device?->id,
                'site_id' => $a->site_id,
                'latitude' => $a->device ? (float) $a->device->latitude : null,
                'longitude' => $a->device ? (float) $a->device->longitude : null,
                'asset_name' => $a->asset?->name,
                'notes' => $a->notes ? substr($a->notes, 0, 120) : null,
            ])->values(),
            'all_sites' => $allSites->map(fn (Site $s) => [
                'id' => $s->id,
                'name' => $s->name,
            ])->values(),
            'stats' => $stats,
            'filters' => $request->only(['site_id', 'type', 'status', 'alert_only']),
        ]);
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyDeviceSiteScope($query, array $siteIds, ?int $organizationId): void
    {
        if ($siteIds === [] || $organizationId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($siteQuery) use ($siteIds, $organizationId) {
            $siteQuery->whereIn('site_id', $siteIds)
                ->orWhere(function ($clientFallback) use ($siteIds, $organizationId) {
                    $clientFallback->whereNull('site_id')
                        ->whereIn('client_id', Client::query()
                            ->select('id')
                            ->where('organization_id', $organizationId)
                            ->whereIn('site_id', $siteIds));
                })
                ->orWhere(function ($assetFallback) use ($siteIds) {
                    $assetFallback->whereNull('site_id')
                        ->whereNull('client_id')
                        ->whereIn('asset_id', $this->assetIdsForSites($siteIds));
                });
        });
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyGeofenceSiteScope($query, array $siteIds): void
    {
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($siteQuery) use ($siteIds) {
            $siteQuery->whereIn('site_id', $siteIds)
                ->orWhere(function ($assetFallback) use ($siteIds) {
                    $assetFallback->whereNull('site_id')
                        ->whereHas('asset', fn ($assetQuery) => $this->applyAssetSiteScope($assetQuery, $siteIds));
                });
        });
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyAlertSiteScope($query, array $siteIds, ?int $organizationId): void
    {
        if ($siteIds === [] || $organizationId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($siteQuery) use ($siteIds, $organizationId) {
            $siteQuery->whereIn('site_id', $siteIds)
                ->orWhere(function ($siteFallback) use ($siteIds, $organizationId) {
                    $siteFallback->whereNull('site_id')
                        ->where(function ($clientOrDevice) use ($siteIds, $organizationId) {
                            $clientOrDevice->whereHas('client', fn ($clientQuery) => $clientQuery
                                ->where('organization_id', $organizationId)
                                ->whereIn('site_id', $siteIds))
                                ->orWhere(function ($deviceFallback) use ($siteIds, $organizationId) {
                                    $deviceFallback->whereNull('client_id')
                                        ->whereHas('device', fn ($deviceQuery) => $this->applyDeviceSiteScope(
                                            $deviceQuery,
                                            $siteIds,
                                            $organizationId,
                                        ));
                                });
                        });
                });
        });
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function applyAssetSiteScope($query, array $siteIds): void
    {
        if ($siteIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($siteQuery) use ($siteIds) {
            $siteQuery->whereIn('site_id', $siteIds)
                ->orWhere(function ($homeSiteFallback) use ($siteIds) {
                    $homeSiteFallback->whereNull('site_id')
                        ->whereIn('home_site_id', $siteIds);
                });
        });
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    protected function assetIdsForSites(array $siteIds)
    {
        $query = Asset::query()->select('id');
        $this->applyAssetSiteScope($query, $siteIds);

        return $query;
    }

    /**
     * @return array<int, string>
     */
    protected function alertBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }
}
