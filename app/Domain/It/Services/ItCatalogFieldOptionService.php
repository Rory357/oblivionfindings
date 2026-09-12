<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Resolve current access independently of the discovery page limit.
     *
     * @return array{id: int, name: string, detail: string|null}|null
     */
    public function find(User $actor, string $type, int $id): ?array
    {
        if ($actor->approved_at === null || ! in_array($type, self::TYPES, true) || $id < 1) {
            return null;
        }

        if ($type === 'asset') {
            $asset = $this->assetQuery($actor)->whereKey($id)->first(['id', 'site_id', 'name', 'asset_tag']);

            return $asset ? [
                'id' => (int) $asset->id,
                'name' => $asset->name,
                'detail' => $asset->asset_tag ? 'Tag '.$asset->asset_tag : $asset->site?->name,
            ] : null;
        }

        $profile = $this->profileQuery($actor)
            ->where($type === 'user' ? 'user_id' : 'id', $id)
            ->first(['id', 'user_id', 'primary_site_id']);
        if (! $profile || ($type === 'user' && ! $profile->user)) {
            return null;
        }

        return [
            'id' => $type === 'user' ? (int) $profile->user->id : (int) $profile->id,
            'name' => $profile->user?->name ?: 'Employee profile '.$profile->id,
            'detail' => $profile->primarySite?->name,
        ];
    }

    /**
     * Bounded keyset discovery. The controller derives the type from a visible
     * published field; this method supplies the canonical current access scope.
     *
     * @return array{options: list<array{id: int, name: string, detail: string|null}>, next_cursor: int|null}
     */
    public function search(User $actor, string $type, string $search, ?int $after = null): array
    {
        if ($actor->approved_at === null || ! in_array($type, self::TYPES, true)) {
            return ['options' => [], 'next_cursor' => null];
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
        $query = $type === 'asset' ? $this->assetQuery($actor) : $this->profileQuery($actor);
        if ($type === 'user') {
            $query->whereHas('user');
        }
        if ($search !== '') {
            if ($type === 'asset') {
                $query->where(fn (Builder $match) => $match
                    ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("asset_tag LIKE ? ESCAPE '!'", [$pattern]));
            } else {
                $query->whereHas('user', fn (Builder $users) => $users->whereRaw("name LIKE ? ESCAPE '!'", [$pattern]));
            }
        }
        $rows = $query->when($after !== null, fn (Builder $page) => $page->where('id', '>', $after))
            ->reorder('id')->limit(51)
            ->get($type === 'asset' ? ['id', 'site_id', 'name', 'asset_tag'] : ['id', 'user_id', 'primary_site_id']);
        $page = $rows->take(50);

        return [
            'options' => $page->map(fn ($record): array => $type === 'asset' ? [
                'id' => (int) $record->id,
                'name' => $record->name,
                'detail' => $record->asset_tag ? 'Tag '.$record->asset_tag : $record->site?->name,
            ] : [
                'id' => $type === 'user' ? (int) $record->user->id : (int) $record->id,
                'name' => $record->user?->name ?: 'Employee profile '.$record->id,
                'detail' => $record->primarySite?->name,
            ])->values()->all(),
            'next_cursor' => $rows->count() > 50 ? (int) $page->last()->id : null,
        ];
    }

    /** @return Builder<HrEmployeeProfile> */
    private function profileQuery(User $actor): Builder
    {
        return $this->provisioningAccess
            ->selectableProfiles($actor)
            ->when(! $actor->canDo('it.manage'), fn ($query) => $query->where('user_id', $actor->id))
            ->with(['user:id,name', 'primarySite:id,name'])
            ->orderBy('id');
    }

    /** @return Collection<int, HrEmployeeProfile> */
    private function profiles(User $actor): Collection
    {
        return $this->profileQuery($actor)->limit(200)->get(['id', 'user_id', 'primary_site_id']);
    }

    /** @return Builder<Asset> */
    private function assetQuery(User $actor): Builder
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
            ->orderBy('name')->orderBy('id');
    }

    /** @return Collection<int, Asset> */
    private function assets(User $actor): Collection
    {
        return $this->assetQuery($actor)->limit(200)->get(['id', 'site_id', 'name', 'asset_tag']);
    }
}
