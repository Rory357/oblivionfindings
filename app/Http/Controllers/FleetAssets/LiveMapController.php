<?php

namespace App\Http\Controllers\FleetAssets;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetGeofence;
use App\Models\ControlRoomAlert;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class LiveMapController extends Controller
{
    private const SITE_BYPASS_PERMISSIONS = ['fleet.manage'];

    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function __invoke(Request $request)
    {
        $hasFleetFields = Schema::hasColumn('assets', 'home_site_id');
        $user = $request->user();
        $organizationId = $this->organizationId($user);
        $tenantSiteIds = $organizationId === null
            ? []
            : Site::query()
                ->where('tenant_id', $organizationId)
                ->pluck('id')
                ->map(fn ($siteId) => (int) $siteId)
                ->all();
        $profileSiteIds = array_values(array_intersect(
            $this->siteAccess->accessibleSiteIds($user),
            $tenantSiteIds,
        ));
        $accessibleSiteIds = $this->siteAccess->canBypass($user, self::SITE_BYPASS_PERMISSIONS)
            ? $tenantSiteIds
            : $profileSiteIds;

        $applyAssetScope = function (Builder $query) use ($accessibleSiteIds, $hasFleetFields): Builder {
            if ($accessibleSiteIds === []) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function (Builder $sites) use ($accessibleSiteIds, $hasFleetFields): void {
                $sites->whereIn('site_id', $accessibleSiteIds);
                if ($hasFleetFields) {
                    $sites->orWhere(function (Builder $homeSite) use ($accessibleSiteIds): void {
                        $homeSite->whereNull('site_id')
                            ->whereIn('home_site_id', $accessibleSiteIds);
                    });
                }
            });
        };
        $accessibleAssetIds = $applyAssetScope(Asset::query())
            ->pluck('id')
            ->map(fn ($assetId) => (int) $assetId)
            ->all();
        $accessibleClientIds = $organizationId === null || $accessibleSiteIds === []
            ? []
            : \App\Models\Client::query()
                ->where('organization_id', $organizationId)
                ->whereIn('site_id', $accessibleSiteIds)
                ->pluck('id')
                ->map(fn ($clientId) => (int) $clientId)
                ->all();

        $eagerLoads = ['fleetState'];
        if ($hasFleetFields) {
            $eagerLoads['homeSite'] = fn ($query) => $organizationId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('tenant_id', $organizationId);
        }

        $selectColumns = ['id', 'name', 'asset_tag', 'category', 'status'];
        if ($hasFleetFields) {
            $selectColumns[] = 'home_site_id';
        }

        $vehicleQuery = $applyAssetScope(Asset::vehicles()->with($eagerLoads));
        $vehicles = $vehicleQuery->get($selectColumns);

        $vehicleMarkers = $vehicles->filter(fn ($v) => $v->fleetState)
            ->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->name,
                'asset_tag' => $v->asset_tag,
                'type' => 'vehicle',
                'lat' => $v->fleetState->latitude,
                'lng' => $v->fleetState->longitude,
                'status' => $v->fleetState->status,
                'speed_kph' => $v->fleetState->speed_kph,
                'heading_deg' => $v->fleetState->heading_deg,
                'last_seen_at' => optional($v->fleetState->last_seen_at)->toISOString(),
                'consent_blocked' => (bool) $v->fleetState->consent_blocked,
                'home_site' => $hasFleetFields && $v->homeSite ? [
                    'id' => $v->homeSite->id,
                    'name' => $v->homeSite->name,
                ] : null,
            ])->values();

        $houseQuery = Site::query()
            ->whereIn('id', $accessibleSiteIds)
            ->where('type', 'house')
            ->whereNotNull('latitude');
        $houses = $houseQuery->get(['id', 'name', 'address_line_1', 'latitude', 'longitude'])
            ->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'type' => 'house',
                'address' => $h->address_line_1,
                'lat' => $h->latitude,
                'lng' => $h->longitude,
            ])->values();

        $geofenceQuery = AssetGeofence::query()
            ->with(['asset' => fn ($query) => $query
                ->whereIn('assets.id', $accessibleAssetIds)
                ->select(['id', 'name'])])
            ->where('is_active', true);
        if ($accessibleSiteIds === []) {
            $geofenceQuery->whereRaw('1 = 0');
        } else {
            $geofenceQuery->where(function (Builder $scope) use ($accessibleSiteIds, $accessibleAssetIds): void {
                $scope->whereIn('site_id', $accessibleSiteIds)
                    ->orWhere(function (Builder $fallback) use ($accessibleAssetIds): void {
                        $fallback->whereNull('site_id')
                            ->where(function (Builder $assets) use ($accessibleAssetIds): void {
                                $assets->whereHas('asset', fn (Builder $asset) => $asset
                                    ->whereIn('assets.id', $accessibleAssetIds))
                                    ->orWhereHas('assignedAssets', fn (Builder $asset) => $asset
                                        ->whereIn('assets.id', $accessibleAssetIds));
                            });
                    });
            });
        }
        $geofences = $geofenceQuery->get()
            ->map(fn ($g) => [
                'id' => $g->id,
                'name' => $g->name,
                'type' => $g->type,
                'breach_type' => $g->breach_type,
                'shape' => $g->shape,
                'asset' => $g->asset ? [
                    'id' => $g->asset->id,
                    'name' => $g->asset->name,
                ] : null,
            ])->values();

        // Open alerts for the hero band — single COUNT query.
        $openAlertsQuery = ControlRoomAlert::query()
            ->actionable()
            ->where(function (Builder $scope) use (
                $accessibleSiteIds,
                $accessibleClientIds,
                $accessibleAssetIds,
            ): void {
                $scope->whereIn('site_id', $accessibleSiteIds)
                    ->orWhere(function (Builder $clientFallback) use ($accessibleClientIds): void {
                        $clientFallback->whereNull('site_id')
                            ->whereNotNull('client_id')
                            ->whereIn('client_id', $accessibleClientIds);
                    })->orWhere(function (Builder $assetFallback) use ($accessibleAssetIds): void {
                        $assetFallback->whereNull('site_id')
                            ->whereNull('client_id')
                            ->whereNotNull('asset_id')
                            ->whereIn('asset_id', $accessibleAssetIds);
                    });
            });
        $openAlerts = $openAlertsQuery->count();

        return Inertia::render('fleet-assets/map', [
            'vehicle_markers' => $vehicleMarkers,
            'house_markers' => $houses,
            'geofences' => $geofences,
            'open_alerts' => $openAlerts,
        ]);
    }

    private function organizationId(?User $user): ?int
    {
        $organizationId = $user?->organization_id;

        return is_numeric($organizationId) && (int) $organizationId > 0
            ? (int) $organizationId
            : null;
    }
}
