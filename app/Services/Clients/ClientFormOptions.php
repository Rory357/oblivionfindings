<?php

namespace App\Services\Clients;

use App\Models\AssetGeofence;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\SiteHouseRoom;

class ClientFormOptions
{
    public function __construct(
        private readonly ClientWorkerEligibility $workers,
    ) {}

    /** @return array<string, mixed> */
    public function forOrganization(?int $organizationId): array
    {
        $defaultServiceContextId = ServiceContext::defaultId();
        if (
            $defaultServiceContextId !== null
            && ! ServiceContext::query()
                ->forOrganization($organizationId)
                ->whereKey($defaultServiceContextId)
                ->exists()
        ) {
            $defaultServiceContextId = null;
        }

        return [
            'sites' => Site::query()
                ->forTenant($organizationId)
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
                ->forOrganization($organizationId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'type', 'name']),
            'keyWorkers' => $this->workers
                ->queryForOrganization($organizationId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'geofences' => AssetGeofence::query()
                ->forOrganization($organizationId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
            'defaultServiceContextId' => $defaultServiceContextId,
        ];
    }
}
