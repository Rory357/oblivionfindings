<?php

namespace App\Services\Fleet;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;

/**
 * The site boundary for trip records and the people named on them. It is
 * the same rule the trip playback pages apply (FleetTripController): a
 * vehicle's trips are visible through its direct Site, else its home Site,
 * else its client's Site, each only while the Site is operational and the
 * client placement agrees; a driver's name is shown only when that person
 * has (or had) an HR placement at one of the viewer's Sites.
 */
final class FleetTripSiteScope
{
    /** @param  list<int>  $siteIds */
    public static function vehicles(array $siteIds): Builder
    {
        $query = Asset::query()->vehicles();
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $siteColumn = $query->qualifyColumn('site_id');
        $homeSiteColumn = $query->qualifyColumn('home_site_id');
        $clientColumn = $query->qualifyColumn('client_id');

        return $query->where(function (Builder $provenance) use ($siteIds, $siteColumn, $homeSiteColumn, $clientColumn): void {
            $provenance->where(function (Builder $directSite) use ($siteIds, $siteColumn, $clientColumn): void {
                $directSite->whereIn($siteColumn, $siteIds)
                    ->whereHas('site', fn (Builder $site) => self::operational($site))
                    ->where(function (Builder $clientAgreement) use ($siteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client) => $client
                                ->whereColumn($client->qualifyColumn('site_id'), $siteColumn)
                                ->whereHas('site', fn (Builder $site) => self::operational($site)));
                    });
            })->orWhere(function (Builder $homeSite) use ($siteIds, $siteColumn, $homeSiteColumn, $clientColumn): void {
                $homeSite->whereNull($siteColumn)
                    ->whereIn($homeSiteColumn, $siteIds)
                    ->whereHas('homeSite', fn (Builder $site) => self::operational($site))
                    ->where(function (Builder $clientAgreement) use ($homeSiteColumn, $clientColumn): void {
                        $clientAgreement->whereNull($clientColumn)
                            ->orWhereHas('client', fn (Builder $client) => $client
                                ->whereColumn($client->qualifyColumn('site_id'), $homeSiteColumn)
                                ->whereHas('site', fn (Builder $site) => self::operational($site)));
                    });
            })->orWhere(function (Builder $clientSite) use ($siteIds, $siteColumn, $homeSiteColumn, $clientColumn): void {
                $clientSite->whereNull($siteColumn)
                    ->whereNull($homeSiteColumn)
                    ->whereNotNull($clientColumn)
                    ->whereHas('client', fn (Builder $client) => $client
                        ->whereIn($client->qualifyColumn('site_id'), $siteIds)
                        ->whereHas('site', fn (Builder $site) => self::operational($site)));
            });
        });
    }

    /**
     * The people among $userIds whose names the viewer may see on trips.
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $siteIds
     * @return array<int, true>
     */
    public static function visiblePeople(array $userIds, array $siteIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, fn ($id) => (int) $id > 0)));
        if ($userIds === [] || $siteIds === []) {
            return [];
        }

        return HrEmployeeProfile::withTrashed()
            ->whereIn('user_id', $userIds)
            ->where(function (Builder $sites) use ($siteIds): void {
                $sites->whereIn('primary_site_id', $siteIds);
                foreach ($siteIds as $siteId) {
                    $sites->orWhereJsonContains('secondary_site_ids', $siteId);
                }
            })
            ->pluck('user_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();
    }

    private static function operational(Builder $query): Builder
    {
        return $query
            ->where($query->qualifyColumn('is_active'), true)
            ->where($query->qualifyColumn('archived'), false)
            ->whereNull($query->qualifyColumn('archived_at'));
    }
}
