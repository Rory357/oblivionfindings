<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Canonical, viewer-specific choices for dynamic catalogue entity fields.
 * Values remain source-domain IDs; this service owns labels and direct-object
 * visibility so published forms never expose or trust arbitrary record IDs.
 */
final class ItCatalogFieldOptionService
{
    public const TYPES = ['employee', 'user', 'asset'];

    public function __construct(
        private readonly ItProvisioningAccessService $provisioningAccess,
        private readonly ItWorkAccessService $workAccess,
    ) {}

    /**
     * @param  list<string>  $types
     * @return array<string, list<array{id: int, name: string, detail: string|null}>>
     */
    public function forTypes(User $actor, array $types = self::TYPES): array
    {
        $types = array_values(array_intersect(self::TYPES, array_unique($types)));
        $options = ['employee' => [], 'user' => [], 'asset' => []];
        if ($actor->approved_at === null) {
            return $options;
        }

        if (array_intersect($types, ['employee', 'user']) !== []) {
            $profiles = $this->profiles($actor);
            if (in_array('employee', $types, true)) {
                $options['employee'] = $profiles->map(fn (HrEmployeeProfile $profile): array => [
                    'id' => (int) $profile->id,
                    'name' => $profile->user?->name ?: 'Employee profile '.$profile->id,
                    'detail' => $profile->primarySite?->name,
                ])->values()->all();
            }
            if (in_array('user', $types, true)) {
                $options['user'] = $profiles
                    ->filter(fn (HrEmployeeProfile $profile): bool => $profile->user !== null)
                    ->map(fn (HrEmployeeProfile $profile): array => [
                        'id' => (int) $profile->user->id,
                        'name' => $profile->user->name,
                        'detail' => $profile->primarySite?->name,
                    ])
                    ->unique('id')
                    ->sortBy('name')
                    ->values()
                    ->all();
            }
        }

        if (in_array('asset', $types, true)) {
            $options['asset'] = $this->assets($actor)->map(fn (Asset $asset): array => [
                'id' => (int) $asset->id,
                'name' => $asset->name,
                'detail' => $asset->asset_tag
                    ? 'Tag '.$asset->asset_tag
                    : $asset->site?->name,
            ])->values()->all();
        }

        return $options;
    }

    /** @return Collection<int, HrEmployeeProfile> */
    private function profiles(User $actor): Collection
    {
        return $this->provisioningAccess
            ->selectableProfiles($actor)
            ->when(! $actor->canDo('it.manage'), fn ($query) => $query->where('user_id', $actor->id))
            ->with(['user:id,name', 'primarySite:id,name'])
            ->orderBy('id')
            ->limit(200)
            ->get(['id', 'user_id', 'primary_site_id']);
    }

    /** @return Collection<int, Asset> */
    private function assets(User $actor): Collection
    {
        $siteIds = $this->workAccess->approvedSiteIds($actor);

        return Asset::query()
            ->where('status', 'active')
            ->when(
                ! $actor->canDo('it.manage'),
                fn ($query) => $query->whereHas('assignments', fn ($assignments) => $assignments
                    ->where('assignee_type', 'staff')
                    ->where('assignee_id', $actor->id)
                    ->whereNull('released_at')),
                function ($query) use ($actor, $siteIds): void {
                    if (! $actor->canDo('it.organisationWide')) {
                        $siteIds === []
                            ? $query->whereRaw('1 = 0')
                            : $query->whereIn('site_id', $siteIds);
                    }
                },
            )
            ->when(
                ! $actor->canDo('it.organisationWide'),
                fn ($query) => $siteIds === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('site_id', $siteIds),
            )
            ->with('site:id,name')
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'site_id', 'name', 'asset_tag']);
    }
}
