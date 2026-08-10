<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrApplication;
use App\Domain\Hr\Models\HrCandidate;
use App\Domain\Hr\Models\HrCandidateDocument;
use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Models\HrJobRequisition;
use App\Domain\Hr\Models\HrOffer;
use App\Domain\Hr\Models\HrReferenceCheck;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Canonical Site provenance for recruitment records.
 *
 * A candidate may have several applications. The candidate is visible only
 * when every application resolves to one accessible Site and any linked
 * requisition agrees with the application's target Site. This prevents one
 * visible application from masking inaccessible or corrupt provenance.
 */
class HrRecruitmentAccessService
{
    /** @var array<int, array{site_ids: list<int>, requisition_ids: list<int>, application_ids: list<int>, candidate_ids: list<int>, offer_ids: list<int>}> */
    private array $scopeCache = [];

    private ?Request $scopeRequest = null;

    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /** @return array{site_ids: list<int>, requisition_ids: list<int>, application_ids: list<int>, candidate_ids: list<int>, offer_ids: list<int>} */
    public function scope(User $viewer): array
    {
        // Route instances can be reused across several HTTP requests in feature
        // tests and long-running workers. Never carry a viewer's old candidate
        // graph into a later request after recruitment records or Site access
        // changed. Holding the prior Request object also prevents object-id reuse
        // from resurrecting stale cache entries.
        $currentRequest = app()->bound('request') ? request() : null;
        if ($this->scopeRequest !== $currentRequest) {
            $this->scopeRequest = $currentRequest;
            $this->scopeCache = [];
        }

        $viewerId = (int) $viewer->getKey();
        if (isset($this->scopeCache[$viewerId])) {
            return $this->scopeCache[$viewerId];
        }

        $siteIds = $this->siteAccess->accessibleSiteIds($viewer);
        $requisitionIds = $siteIds === []
            ? []
            : HrJobRequisition::query()
                ->whereIn('site_id', $siteIds)
                ->whereHas('site', fn (Builder $site): Builder => $site
                    ->active()
                    ->notArchived()
                    ->whereNull('archived_at'))
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

        $applications = HrApplication::query()
            ->with('requisition:id,site_id')
            ->get(['id', 'candidate_id', 'requisition_id', 'target_site_id']);
        $validApplications = $applications
            ->filter(fn (HrApplication $application): bool => $this->applicationSiteId($application, $siteIds) !== null);
        $validApplicationIds = $validApplications
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values();
        $candidateIds = $applications
            ->groupBy('candidate_id')
            ->filter(function (Collection $candidateApplications) use ($validApplicationIds): bool {
                return $candidateApplications->isNotEmpty()
                    && $candidateApplications->every(
                        fn (HrApplication $application): bool => $validApplicationIds->contains((int) $application->id),
                    );
            })
            ->keys()
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
        $applicationIds = $validApplications
            ->whereIn('candidate_id', $candidateIds)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $applicationSiteIds = $validApplications
            ->whereIn('id', $applicationIds)
            ->mapWithKeys(fn (HrApplication $application): array => [
                (int) $application->id => $this->applicationSiteId($application, $siteIds),
            ]);
        $offerIds = $applicationIds === []
            ? []
            : HrOffer::query()
                ->whereIn('application_id', $applicationIds)
                ->get(['id', 'application_id', 'primary_site_id'])
                ->filter(function (HrOffer $offer) use ($applicationSiteIds): bool {
                    $applicationSiteId = $applicationSiteIds->get((int) $offer->application_id);

                    return is_int($applicationSiteId)
                        && (int) $offer->primary_site_id === $applicationSiteId;
                })
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all();

        return $this->scopeCache[$viewerId] = [
            'site_ids' => array_values($siteIds),
            'requisition_ids' => array_values($requisitionIds),
            'application_ids' => array_values($applicationIds),
            'candidate_ids' => array_values($candidateIds),
            'offer_ids' => array_values($offerIds),
        ];
    }

    /** @return Builder<HrCandidate> */
    public function visibleCandidates(User $viewer): Builder
    {
        return HrCandidate::query()->whereKey($this->scope($viewer)['candidate_ids']);
    }

    /** @return Builder<HrApplication> */
    public function visibleApplications(User $viewer): Builder
    {
        return HrApplication::query()->whereKey($this->scope($viewer)['application_ids']);
    }

    /** @return Builder<HrJobRequisition> */
    public function visibleRequisitions(User $viewer): Builder
    {
        return HrJobRequisition::query()->whereKey($this->scope($viewer)['requisition_ids']);
    }

    /** @return Builder<HrOffer> */
    public function visibleOffers(User $viewer): Builder
    {
        return HrOffer::query()->whereKey($this->scope($viewer)['offer_ids']);
    }

    public function visibleCandidate(User $viewer, HrCandidate|int $candidate, bool $lockForUpdate = false): HrCandidate
    {
        return $this->findVisible($this->visibleCandidates($viewer), $candidate, $lockForUpdate);
    }

    public function visibleApplication(User $viewer, HrApplication|int $application, bool $lockForUpdate = false): HrApplication
    {
        return $this->findVisible($this->visibleApplications($viewer), $application, $lockForUpdate);
    }

    public function visibleRequisition(User $viewer, HrJobRequisition|int $requisition, bool $lockForUpdate = false): HrJobRequisition
    {
        return $this->findVisible($this->visibleRequisitions($viewer), $requisition, $lockForUpdate);
    }

    public function visibleOffer(User $viewer, HrOffer|int $offer, bool $lockForUpdate = false): HrOffer
    {
        return $this->findVisible($this->visibleOffers($viewer), $offer, $lockForUpdate);
    }

    public function visibleInterview(User $viewer, HrInterview|int $interview, bool $lockForUpdate = false): HrInterview
    {
        $query = HrInterview::query()
            ->whereIn('application_id', $this->scope($viewer)['application_ids']);

        return $this->findVisible($query, $interview, $lockForUpdate);
    }

    public function visibleReference(User $viewer, HrReferenceCheck|int $reference, bool $lockForUpdate = false): HrReferenceCheck
    {
        $query = HrReferenceCheck::query()
            ->whereIn('application_id', $this->scope($viewer)['application_ids']);

        return $this->findVisible($query, $reference, $lockForUpdate);
    }

    public function visibleDocument(User $viewer, HrCandidateDocument|int $document, bool $lockForUpdate = false): HrCandidateDocument
    {
        $query = HrCandidateDocument::query()
            ->whereIn('candidate_id', $this->scope($viewer)['candidate_ids']);

        return $this->findVisible($query, $document, $lockForUpdate);
    }

    /** @return Collection<int, User> */
    public function currentUsers(User $viewer): Collection
    {
        $siteIds = $this->scope($viewer)['site_ids'];

        return $this->currentStaff->currentUsersQuery()
            ->with('hrEmployeeProfile:user_id,primary_site_id,secondary_site_ids')
            ->orderBy('name')
            ->get()
            ->filter(function (User $user) use ($siteIds): bool {
                $profile = $user->hrEmployeeProfile;
                if (! $profile) {
                    return false;
                }

                $profileSiteIds = collect([
                    $profile->primary_site_id,
                    ...($profile->secondary_site_ids ?? []),
                ])
                    ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->unique();

                return $profileSiteIds->isNotEmpty()
                    && $profileSiteIds->diff($siteIds)->isEmpty();
            })
            ->values();
    }

    /** @return Collection<int, User> */
    public function currentUsersAtSite(User $viewer, int $siteId): Collection
    {
        $this->assertSite($viewer, $siteId);

        return $this->currentUsers($viewer)
            ->filter(function (User $user) use ($siteId): bool {
                $profile = $user->hrEmployeeProfile;
                if (! $profile) {
                    return false;
                }

                return collect([
                    $profile->primary_site_id,
                    ...($profile->secondary_site_ids ?? []),
                ])
                    ->filter(fn (mixed $id): bool => is_numeric($id))
                    ->map(fn (mixed $id): int => (int) $id)
                    ->contains($siteId);
            })
            ->values();
    }

    public function assertSite(User $viewer, int $siteId): void
    {
        abort_unless(in_array($siteId, $this->scope($viewer)['site_ids'], true), 404);
    }

    public function applicationSiteIdFor(User $viewer, HrApplication|int $application): int
    {
        $application = $this->visibleApplication($viewer, $application);
        $application->loadMissing('requisition:id,site_id');
        $siteId = $this->applicationSiteId($application, $this->scope($viewer)['site_ids']);

        abort_unless($siteId !== null, 404);

        return $siteId;
    }

    private function applicationSiteId(HrApplication $application, array $accessibleSiteIds): ?int
    {
        $targetSiteId = is_numeric($application->target_site_id)
            ? (int) $application->target_site_id
            : null;
        $requisitionSiteId = is_numeric($application->requisition?->site_id)
            ? (int) $application->requisition->site_id
            : null;

        if ($application->requisition_id !== null && $requisitionSiteId === null) {
            return null;
        }
        if ($targetSiteId !== null
            && $requisitionSiteId !== null
            && $targetSiteId !== $requisitionSiteId
        ) {
            return null;
        }

        $siteId = $targetSiteId ?? $requisitionSiteId;

        return $siteId !== null && in_array($siteId, $accessibleSiteIds, true)
            ? $siteId
            : null;
    }

    /** @template TModel of \Illuminate\Database\Eloquent\Model
     * @param  Builder<TModel>  $query
     * @param  TModel|int  $model
     * @return TModel
     */
    private function findVisible(Builder $query, object|int $model, bool $lockForUpdate): object
    {
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($model instanceof Model
            ? $model->getKey()
            : $model);
    }
}
