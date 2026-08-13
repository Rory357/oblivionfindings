<?php

namespace App\Domain\Finance\Services;

use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single authorization boundary for Financial Insights object reads.
 *
 * The application is single-tenant and multi-site. Ordinary finance dashboard
 * access never grants application-wide visibility: current accessible Sites and
 * the canonical support-worker Client relationship remain mandatory. The one
 * global exception is the separately seeded finance.insights.viewAllSites
 * permission.
 *
 * Deleted Clients are deliberately unavailable to both scoped and global users.
 * Historical deleted rows may contribute only to authorised Site-level occupancy
 * aggregates; they are never resolved as a client relationship or named in a
 * client-level Financial Insights payload.
 */
final class FinancialInsightsScopeResolver
{
    public const BASE_PERMISSION = 'finance.dashboard';

    public const GLOBAL_PERMISSION = 'finance.insights.viewAllSites';

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function resolveAggregate(?User $user): FinancialInsightsScopeDecision
    {
        if (! $user || ! $user->canDo(self::BASE_PERMISSION)) {
            return FinancialInsightsScopeDecision::denied();
        }

        $isGlobal = $user->canDo(self::GLOBAL_PERMISSION);
        $siteIds = $this->siteAccess->accessibleSiteIds(
            $user,
            $isGlobal ? [self::GLOBAL_PERMISSION] : [],
        );

        $clientIds = $siteIds === []
            ? []
            : Client::query()
                ->whereIn('site_id', $siteIds)
                ->when(
                    ! $isGlobal,
                    fn (Builder $clients): Builder => $clients->whereHas(
                        'supportWorkers',
                        fn (Builder $workers): Builder => $workers->whereKey($user->id),
                    ),
                )
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($clientId): int => (int) $clientId)
                ->all();

        if ($isGlobal) {
            return FinancialInsightsScopeDecision::global($siteIds, $clientIds);
        }

        return $siteIds === []
            ? FinancialInsightsScopeDecision::denied()
            : FinancialInsightsScopeDecision::accessibleSites($siteIds, $clientIds);
    }

    public function resolveSite(?User $user, int $siteId): FinancialInsightsScopeDecision
    {
        $aggregate = $this->resolveAggregate($user);
        if ($aggregate->isDenied() || ! $this->activeSiteQuery($aggregate->siteIds)->whereKey($siteId)->exists()) {
            return FinancialInsightsScopeDecision::denied();
        }

        return $aggregate->scope === FinancialInsightsScope::Global
            ? FinancialInsightsScopeDecision::global($aggregate->siteIds, $aggregate->clientIds, $siteId)
            : FinancialInsightsScopeDecision::accessibleSites(
                $aggregate->siteIds,
                $aggregate->clientIds,
                $siteId,
            );
    }

    public function resolveClient(?User $user, int $clientId): FinancialInsightsScopeDecision
    {
        $aggregate = $this->resolveAggregate($user);
        if ($aggregate->isDenied()) {
            return FinancialInsightsScopeDecision::denied();
        }

        // Client::query() intentionally excludes soft-deleted Clients. Select
        // only the relationship columns needed to decide access before any name,
        // amount, count, status, or deleted-state field can reach the controller.
        $client = Client::query()
            ->whereKey($clientId)
            ->whereNotNull('site_id')
            ->whereIn('site_id', $aggregate->siteIds)
            ->whereIn('id', $aggregate->clientIds)
            ->whereHas('site', fn (Builder $site): Builder => $this->activeSiteQuery($site))
            ->first(['id', 'site_id']);

        if (! $client) {
            return FinancialInsightsScopeDecision::denied();
        }

        $siteId = (int) $client->site_id;

        return $aggregate->scope === FinancialInsightsScope::Global
            ? FinancialInsightsScopeDecision::global(
                $aggregate->siteIds,
                $aggregate->clientIds,
                $siteId,
                (int) $client->id,
            )
            : FinancialInsightsScopeDecision::clientRelationship(
                $aggregate->siteIds,
                $aggregate->clientIds,
                $siteId,
                (int) $client->id,
            );
    }

    /**
     * @param  list<int>  $siteIds
     */
    private function activeSiteQuery(array|Builder $siteIds): Builder
    {
        $query = $siteIds instanceof Builder ? $siteIds : Site::query()->whereIn('id', $siteIds);

        return $query
            ->active()
            ->notArchived()
            ->whereNull($query->qualifyColumn('archived_at'));
    }
}
