<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\ItStaffDirectory;
use App\Models\ItProvisioningRequest;
use App\Models\ItProvisioningWorkflow;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** Canonical Site and responsibility boundary for joiner/mover/leaver work. */
final class ItProvisioningAccessService
{
    public function __construct(private readonly ItWorkAccessService $workAccess) {}

    public function canManage(User $actor, ItProvisioningRequest $request): bool
    {
        if ($actor->approved_at === null || ! $actor->canDo('it.manage')) {
            return false;
        }

        $canonical = ItProvisioningRequest::query()
            ->with(['employeeProfile:id,primary_site_id', 'workflow:id,site_id_snapshot', 'responsibleTeam.members:id'])
            ->find($request->getKey());
        if (! $canonical) {
            return false;
        }

        $siteId = $this->effectiveSiteId($canonical);
        if ($siteId === null) {
            return $actor->canDo('it.organisationWide');
        }
        if (! $this->siteIsOperational($siteId)) {
            return false;
        }
        if (in_array($siteId, $this->workAccess->approvedSiteIds($actor), true)) {
            return true;
        }
        if ((int) $canonical->assigned_to_user_id === (int) $actor->id) {
            return true;
        }

        return $canonical->responsibleTeam?->is_active
            && ((int) $canonical->responsibleTeam->manager_user_id === (int) $actor->id
                || $canonical->responsibleTeam->members->contains('id', $actor->id));
    }

    public function canView(User $actor, ItProvisioningRequest $request): bool
    {
        if ($actor->approved_at === null || (! $actor->canDo('it.view') && ! $actor->canDo('it.manage'))) {
            return false;
        }

        $canonical = ItProvisioningRequest::query()
            ->with(['employeeProfile:id,primary_site_id', 'workflow:id,site_id_snapshot', 'responsibleTeam.members:id'])
            ->find($request->getKey());
        if (! $canonical) {
            return false;
        }

        $siteId = $this->effectiveSiteId($canonical);
        if ($siteId === null) {
            return $actor->canDo('it.organisationWide');
        }
        if (! $this->siteIsOperational($siteId)) {
            return false;
        }

        return in_array($siteId, $this->workAccess->approvedSiteIds($actor), true)
            || (int) $canonical->assigned_to_user_id === (int) $actor->id
            || ($canonical->responsibleTeam?->is_active
                && ((int) $canonical->responsibleTeam->manager_user_id === (int) $actor->id
                    || $canonical->responsibleTeam->members->contains('id', $actor->id)));
    }

    /** @param Builder<ItProvisioningRequest> $query */
    public function applyRequestScope(Builder $query, User $actor): Builder
    {
        if ($actor->approved_at === null || (! $actor->canDo('it.view') && ! $actor->canDo('it.manage'))) {
            return $query->whereRaw('1 = 0');
        }

        $approvedSiteIds = $this->workAccess->approvedSiteIds($actor);
        $operationalSiteIds = Site::query()
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $query->where(function (Builder $visible) use ($actor, $approvedSiteIds, $operationalSiteIds): void {
            if ($approvedSiteIds !== []) {
                $visible->where(fn (Builder $approved): Builder => $this->whereEffectiveSiteIn($approved, $approvedSiteIds));
            } else {
                $visible->whereRaw('1 = 0');
            }

            if ($operationalSiteIds !== []) {
                $visible->orWhere(function (Builder $responsible) use ($actor, $operationalSiteIds): void {
                    $this->whereEffectiveSiteIn($responsible, $operationalSiteIds)
                        ->where(function (Builder $assignment) use ($actor): void {
                            $assignment->where('assigned_to_user_id', $actor->id)
                                ->orWhereHas('responsibleTeam', fn (Builder $team): Builder => $team
                                    ->where('is_active', true)
                                    ->where(function (Builder $member) use ($actor): void {
                                        $member->where('manager_user_id', $actor->id)
                                            ->orWhereHas('members', fn (Builder $users): Builder => $users->whereKey($actor->id));
                                    }));
                        });
                });
            }

            if ($actor->canDo('it.organisationWide')) {
                $visible->orWhere(fn (Builder $wide): Builder => $this->whereEffectiveSiteIsNull($wide));
            }
        });
    }

    /** @param Builder<ItProvisioningWorkflow> $query */
    public function applyWorkflowScope(Builder $query, User $actor): Builder
    {
        if ($actor->approved_at === null || (! $actor->canDo('it.view') && ! $actor->canDo('it.manage'))) {
            return $query->whereRaw('1 = 0');
        }

        $siteIds = $this->workAccess->approvedSiteIds($actor);

        return $query->where(function (Builder $visible) use ($actor, $siteIds): void {
            if ($siteIds !== []) {
                $visible->whereIn('site_id_snapshot', $siteIds)
                    ->orWhere(function (Builder $current) use ($siteIds): void {
                        $current->whereNull('site_id_snapshot')
                            ->whereHas('employeeProfile', fn (Builder $profile): Builder => $profile
                                ->whereIn('primary_site_id', $siteIds));
                    });
            } else {
                $visible->whereRaw('1 = 0');
            }

            if ($actor->canDo('it.organisationWide')) {
                $visible->orWhere(function (Builder $wide): void {
                    $wide->whereNull('site_id_snapshot')
                        ->whereHas('employeeProfile', fn (Builder $profile): Builder => $profile
                            ->whereNull('primary_site_id'));
                });
            }

            $visible->orWhereHas(
                'requests',
                fn (Builder $requests): Builder => $this->applyRequestScope($requests, $actor),
            );
        });
    }

    public function canSelectProfile(User $actor, HrEmployeeProfile $profile): bool
    {
        $canonical = HrEmployeeProfile::query()
            ->whereKey($profile->getKey())
            ->where('is_active', true)
            ->first(['id', 'primary_site_id']);
        if (! $canonical) {
            return false;
        }

        if ($canonical->primary_site_id === null) {
            return $actor->canDo('it.manage') && $actor->canDo('it.organisationWide');
        }

        return $actor->canDo('it.manage')
            && in_array((int) $canonical->primary_site_id, $this->workAccess->approvedSiteIds($actor), true);
    }

    public function canRequestForProfile(User $actor, HrEmployeeProfile $profile): bool
    {
        $canonical = HrEmployeeProfile::query()
            ->whereKey($profile->getKey())
            ->where('is_active', true)
            ->first(['id', 'user_id', 'primary_site_id']);
        if (! $canonical) {
            return false;
        }

        if ((int) $canonical->user_id === (int) $actor->id) {
            return $canonical->primary_site_id !== null
                && in_array((int) $canonical->primary_site_id, $this->workAccess->approvedSiteIds($actor), true);
        }

        return $this->canSelectProfile($actor, $canonical);
    }

    /** @return Builder<HrEmployeeProfile> */
    public function selectableProfiles(User $actor): Builder
    {
        $query = HrEmployeeProfile::query()->where('is_active', true);
        $siteIds = $this->workAccess->approvedSiteIds($actor);

        return $query->where(function (Builder $visible) use ($actor, $siteIds): void {
            if ($siteIds !== []) {
                $visible->whereIn('primary_site_id', $siteIds);
            } else {
                $visible->whereRaw('1 = 0');
            }
            if ($actor->canDo('it.organisationWide')) {
                $visible->orWhereNull('primary_site_id');
            }
        });
    }

    public function canAssignAgentForRequest(User $agent, ItProvisioningRequest $request): bool
    {
        return ItStaffDirectory::agents()->contains('id', $agent->id)
            && $this->canManage($agent, $request);
    }

    public function canAssignAgentForProfile(User $agent, HrEmployeeProfile $profile): bool
    {
        return ItStaffDirectory::agents()->contains('id', $agent->id)
            && $this->agentCoversSite(
                $agent,
                $profile->primary_site_id !== null ? (int) $profile->primary_site_id : null,
            );
    }

    public function siteIdFor(ItProvisioningRequest $request): ?int
    {
        $canonical = ItProvisioningRequest::query()
            ->with(['employeeProfile:id,primary_site_id', 'workflow:id,site_id_snapshot'])
            ->find($request->getKey());

        return $canonical ? $this->effectiveSiteId($canonical) : null;
    }

    private function effectiveSiteId(ItProvisioningRequest $request): ?int
    {
        $snapshot = $request->workflow?->site_id_snapshot;
        $candidate = $snapshot ?? $request->employeeProfile?->primary_site_id;

        return is_numeric($candidate) && (int) $candidate > 0 ? (int) $candidate : null;
    }

    private function agentCoversSite(User $agent, ?int $siteId): bool
    {
        if ($siteId === null) {
            return $agent->canDo('it.organisationWide');
        }

        return $this->siteIsOperational($siteId)
            && in_array($siteId, $this->workAccess->approvedSiteIds($agent), true);
    }

    private function siteIsOperational(int $siteId): bool
    {
        return Site::query()
            ->whereKey($siteId)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->exists();
    }

    /** @param Builder<ItProvisioningRequest> $query @param list<int> $siteIds */
    private function whereEffectiveSiteIn(Builder $query, array $siteIds): Builder
    {
        return $query->where(function (Builder $site) use ($siteIds): void {
            $site->whereHas('workflow', fn (Builder $workflow): Builder => $workflow
                ->whereNotNull('site_id_snapshot')
                ->whereIn('site_id_snapshot', $siteIds))
                ->orWhere(function (Builder $current) use ($siteIds): void {
                    $current->where(function (Builder $withoutSnapshot): void {
                        $withoutSnapshot->whereDoesntHave('workflow')
                            ->orWhereHas('workflow', fn (Builder $workflow): Builder => $workflow
                                ->whereNull('site_id_snapshot'));
                    })->whereHas('employeeProfile', fn (Builder $profile): Builder => $profile
                        ->whereIn('primary_site_id', $siteIds));
                });
        });
    }

    /** @param Builder<ItProvisioningRequest> $query */
    private function whereEffectiveSiteIsNull(Builder $query): Builder
    {
        return $query->where(function (Builder $wide): void {
            $wide->where(function (Builder $withoutSnapshot): void {
                $withoutSnapshot->whereDoesntHave('workflow')
                    ->orWhereHas('workflow', fn (Builder $workflow): Builder => $workflow
                        ->whereNull('site_id_snapshot'));
            })->whereHas('employeeProfile', fn (Builder $profile): Builder => $profile
                ->whereNull('primary_site_id'));
        });
    }
}
