<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** The Fleet projection and its entry counts share one permitted source universe. */
class FleetAlertScope
{
    public const SOURCES = ['fleet', 'asset', 'tracker', 'geofence', 'queclink_fleet'];

    public const BYPASS = ['reports.viewAny', 'fleet.manage'];

    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function query(User $actor, array $filters = []): Builder
    {
        $query = ControlRoomAlert::query()->whereIn('source', self::SOURCES);
        $this->sites->applyAlertScope($query, $actor, self::BYPASS);
        if (! empty($filters['site_id'])) {
            $query->where('site_id', (int) $filters['site_id']);
        }
        if (! empty($filters['asset_id'])) {
            $query->where('asset_id', (int) $filters['asset_id']);
        }
        if (in_array($filters['entity'] ?? null, ['vehicle', 'asset'], true)) {
            $vehicle = $filters['entity'] === 'vehicle';
            $query->whereHas('asset', fn ($asset) => $vehicle
                ? $asset->vehicles()
                : $asset->whereNotIn('assets.id', Asset::query()->vehicles()->select('id')));
        }

        return $query;
    }
}
