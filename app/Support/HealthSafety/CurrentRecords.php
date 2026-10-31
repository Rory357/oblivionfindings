<?php

namespace App\Support\HealthSafety;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * "Current records only" for the Incidents register and the H&S lists (MASTER for Stephan, 30 Oct): by default a
 * list hides rows at an archived or deleted Site and rows about a removed (soft-deleted) client. `?records=all`
 * shows them again. This only narrows a list; it never grants reading, and a record's own page still opens.
 */
final class CurrentRecords
{
    public const PARAM = 'records';

    public const ALL = 'all';

    public static function includeArchived(Request $request): bool
    {
        return $request->query(self::PARAM) === self::ALL;
    }

    /** The value a page echoes back in its filters: 'all' or 'current'. */
    public static function mode(Request $request): string
    {
        return self::includeArchived($request) ? self::ALL : 'current';
    }

    /** Rows whose Site column is empty or names a Site that is neither archived nor deleted. */
    public static function onCurrentSites(Builder $query, string $siteColumn): Builder
    {
        return $query->where(fn (Builder $scope) => $scope
            ->whereNull($siteColumn)
            ->orWhereIn($siteColumn, self::currentSiteIds()));
    }

    /**
     * Rows whose client column is empty or names a client who is not removed and whose own Site (if any) is
     * current.
     */
    public static function onCurrentClients(Builder $query, string $clientColumn): Builder
    {
        return $query->where(fn (Builder $scope) => $scope
            ->whereNull($clientColumn)
            ->orWhereIn($clientColumn, fn (QueryBuilder $clients) => $clients
                ->select('clients.id')
                ->from('clients')
                ->whereNull('clients.deleted_at')
                ->where(fn (QueryBuilder $site) => $site
                    ->whereNull('clients.site_id')
                    ->orWhereIn('clients.site_id', self::currentSiteIds()))));
    }

    /** Subquery of Site ids that are neither archived nor deleted. */
    public static function currentSiteIds(): \Closure
    {
        return fn (QueryBuilder $sites) => $sites
            ->select('sites.id')
            ->from('sites')
            ->whereNull('sites.deleted_at')
            ->where(fn (QueryBuilder $archived) => $archived
                ->where('sites.archived', false)
                ->orWhereNull('sites.archived'));
    }
}
