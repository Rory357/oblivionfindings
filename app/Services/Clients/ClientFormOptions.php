<?php

namespace App\Services\Clients;

use App\Models\AssetGeofence;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteHouseRoom;
use App\Models\User;
use App\Services\UserSiteAccessService;

class ClientFormOptions
{
    public function __construct(
        private readonly ClientWorkerEligibility $workers,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    /** @return array<string, mixed> */
    public function forViewer(?User $viewer, ?int $siteId = null): array
    {
        $availableSiteIds = $this->siteAccess->accessibleSiteIds(
            $viewer,
            ['clients.create'],
        );
        if ($siteId !== null) {
            $availableSiteIds = in_array($siteId, $availableSiteIds, true)
                ? [$siteId]
                : [];
        }

        $defaultServiceContextId = ServiceContext::defaultId();
        if (
            $defaultServiceContextId !== null
            && ! ServiceContext::query()
                ->availableToSites($availableSiteIds)
                ->whereKey($defaultServiceContextId)
                ->exists()
        ) {
            $defaultServiceContextId = null;
        }

        return [
            'sites' => Site::query()
                ->whereIn('id', $availableSiteIds)
                ->where('is_active', true)
                ->with(['houseRooms' => fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_assignable', true)
                    ->whereNull('assigned_client_id')
                    ->orderBy('sort_order')
                    ->orderBy('name')])
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'rooms' => $site->houseRooms->map(fn (SiteHouseRoom $room) => [
                        'id' => $room->id,
                        'name' => $room->name,
                        'notes' => $room->notes,
                    ])->values(),
                ]),
            'serviceContexts' => ServiceContext::query()
                ->availableToSites($availableSiteIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'site_id', 'type', 'name']),
            'keyWorkers' => ($siteId !== null
                ? $this->workers->queryForSite($siteId)
                : $this->workers->queryForViewer($viewer, ['clients.create']))
                ->orderBy('name')
                ->get(['id', 'name']),
            'geofences' => AssetGeofence::query()
                ->where('is_active', true)
                ->whereIn('site_id', $availableSiteIds)
                ->whereIn('scope', ['house', 'resident'])
                ->orderBy('name')
                ->get(['id', 'site_id', 'name']),
            'defaultServiceContextId' => $defaultServiceContextId,
        ];
    }
}
