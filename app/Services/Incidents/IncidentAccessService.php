<?php

namespace App\Services\Incidents;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** One record boundary for registers, direct links, attachments and task projections. */
final class IncidentAccessService
{
    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function apply(Builder $query, User $viewer, ?CurrentAuthorizationReads $reads = null): Builder
    {
        if (! $viewer->canDo('incidents.viewAny') && ! $viewer->canDo('incidents.viewAssigned')) {
            return $query->whereRaw('1 = 0');
        }

        $this->applySiteScope($query, $viewer, $reads);

        if (! $viewer->canDo('incidents.viewAny')) {
            $query->whereHas('client.supportWorkers', fn (Builder $staff) => $staff->whereKey($viewer->id));
        }

        return $reads ? $reads->query($query) : $query;
    }

    public function canView(User $viewer, ClientIncident $incident, ?CurrentAuthorizationReads $reads = null): bool
    {
        return $this->apply(ClientIncident::query(), $viewer, $reads)->whereKey($incident->getKey())->exists();
    }

    /** Current permitted people, plus people in readable historical occurrences. */
    public function peopleForFilters(User $viewer): Builder
    {
        $query = Client::query();
        if (! $viewer->canDo('incidents.viewAny') && ! $viewer->canDo('incidents.viewAssigned')) {
            return $query->whereRaw('1 = 0');
        }

        $siteIds = $this->sites->accessibleHealthSafetySiteIds($viewer);
        $query->where(fn (Builder $people) => $people
            ->whereIn('site_id', $siteIds)
            ->orWhereIn('id', $this->apply(ClientIncident::query(), $viewer)->select('client_id')));

        if (! $viewer->canDo('incidents.viewAny')) {
            $query->whereHas('supportWorkers', fn (Builder $staff) => $staff->whereKey($viewer->id));
        }

        return $query;
    }

    /** Action capabilities remain separate; they never broaden the historical Site boundary. */
    public function canAccessSite(User $viewer, ClientIncident $incident): bool
    {
        return $this->applySiteScope(ClientIncident::query(), $viewer)->whereKey($incident->getKey())->exists();
    }

    public function applySiteScope(Builder $query, User $viewer, ?CurrentAuthorizationReads $reads = null): Builder
    {
        if (! $viewer->canDo('hr.cases.view')) {
            $query->where(fn (Builder $privacy) => $privacy
                ->where('is_hr_confidential', false)->orWhereNull('is_hr_confidential'));
        }

        return $this->applySiteBoundary($query, $viewer, $reads);
    }

    /**
     * The incident's Site boundary alone, without the HR-confidential content rule. Only the restricted H&S event
     * check uses it on its own: the H&S event shows at the viewer's Sites, while the incident itself (and so any
     * HR-confidential content) still needs applySiteScope().
     */
    public function applySiteBoundary(Builder $query, User $viewer, ?CurrentAuthorizationReads $reads = null): Builder
    {
        // This explicit H&S grant includes historical/unknown-site records for
        // authorised reconciliation. Generic report or Site administration does not.
        if ($this->sites->canAccessAllHealthSafetySites($viewer)) {
            return $query;
        }

        // Inside one read session the viewer's Sites are read once (speed, 31 Oct: the task count read the same
        // profile and Site rows twice per incident, ~900 queries for a manager). The session holds those rows locked.
        $siteIds = $reads === null
            ? $this->sites->accessibleSiteIds($viewer)
            : $reads->rememberFor($viewer, 'incident-site-boundary', fn (): array => $this->sites->accessibleSiteIds($viewer, [], $reads));

        return $this->forSiteIds($query, $siteIds);
    }

    /** @param list<int> $siteIds */
    public function forSiteIds(Builder $query, array $siteIds): Builder
    {
        if ($siteIds === []) {
            return $query->whereRaw('1 = 0');
        }

        // Recorded occurrence first, then a canonical source review, then the
        // original Shift, with current placement only a last legacy fallback.
        // A conflicting direct H&S link is never evidence of Site authority.
        $canonicalReview = static fn (Builder $review) => $review
            ->where('source_type', ClientIncident::class)
            ->whereColumn('source_id', 'client_incidents.id')
            ->whereNotNull('site_id');

        return $query->where(function (Builder $scope) use ($siteIds, $canonicalReview): void {
            $scope->whereIn($scope->qualifyColumn('site_id'), $siteIds)
                ->orWhere(function (Builder $legacy) use ($siteIds, $canonicalReview): void {
                    $legacy->whereNull($legacy->qualifyColumn('site_id'))
                        ->where(function (Builder $fallback) use ($siteIds, $canonicalReview): void {
                            $fallback->whereHas('hsEvent', fn (Builder $review) => $canonicalReview($review)->whereIn('site_id', $siteIds))
                                ->orWhere(function (Builder $withoutReview) use ($siteIds, $canonicalReview): void {
                                    $withoutReview->whereDoesntHave('hsEvent', $canonicalReview)
                                        ->where(function (Builder $origin) use ($siteIds): void {
                                            $origin->whereHas('shift', fn (Builder $shift) => $shift
                                                ->whereColumn('client_id', 'client_incidents.client_id')
                                                ->whereIn('site_id', $siteIds))
                                                ->orWhere(function (Builder $withoutShift) use ($siteIds): void {
                                                    $withoutShift->whereNull('shift_id')
                                                        ->whereHas('client', fn (Builder $client) => $client->whereIn('site_id', $siteIds));
                                                });
                                        });
                                });
                        });
                });
        });
    }
}
