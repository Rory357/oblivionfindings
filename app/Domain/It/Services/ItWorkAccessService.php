<?php

namespace App\Domain\It\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The single authorisation boundary for IT work items.
 *
 * Participants may see their own request, including sensitive requests, but
 * participation never grants agent actions. Staff access combines an IT
 * capability with sensitivity and one explicit operational scope.
 */
final class ItWorkAccessService
{
    /** @return list<int> */
    public function approvedSiteIds(User $user): array
    {
        $profile = HrEmployeeProfile::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('start_date')
                    ->orWhereDate('start_date', '<=', today());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', today());
            })
            ->first(['primary_site_id', 'secondary_site_ids']);

        $candidateIds = collect([
            $profile?->primary_site_id,
            ...($profile?->secondary_site_ids ?? []),
        ]);

        // Some historic deployments carried a direct users.site_id pointer.
        // It is compatibility input only; Site remains the canonical scope.
        if (Schema::hasColumn('users', 'site_id')) {
            $candidateIds->push(
                User::query()->whereKey($user->getKey())->value('site_id'),
            );
        }

        $ids = $candidateIds
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $operationalIds = Site::query()
            ->whereKey($ids->all())
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        return $ids
            ->filter(fn (int $id): bool => in_array($id, $operationalIds, true))
            ->all();
    }

    public function canView(User $user, ItTicket $ticket): bool
    {
        $canonical = $this->canonicalTicket($ticket);

        if (! $canonical) {
            return false;
        }

        if ($this->isParticipant($user, $canonical)) {
            return true;
        }

        return $this->hasStaffCapability($user)
            && $this->staffCanAccess($user, $canonical);
    }

    public function canWork(User $user, ItTicket $ticket): bool
    {
        $canonical = $this->canonicalTicket($ticket);

        return $canonical !== null
            && $user->canDo('it.manage')
            && $this->staffCanAccess($user, $canonical);
    }

    /**
     * Validate a scope selected for a new or updated ticket.
     *
     * A Site and the organisation-wide marker are mutually exclusive. Site
     * choices come only from the actor's current approved assignments; the
     * exceptional null-Site path requires both agent and wide-scope powers.
     */
    public function canAssignScope(
        User $user,
        ?int $siteId,
        bool $isOrganisationWide,
    ): bool {
        if ($isOrganisationWide) {
            return $siteId === null
                && $user->canDo('it.manage')
                && $user->canDo('it.organisationWide');
        }

        return $siteId !== null
            && in_array($siteId, $this->approvedSiteIds($user), true);
    }

    public function defaultSiteId(User $user): ?int
    {
        return $this->approvedSiteIds($user)[0] ?? null;
    }

    /**
     * Email ingress accepts staff replies only when responsibility is explicit;
     * ordinary Site visibility is not enough to post through an unattended
     * mailbox transport.
     */
    public function isResponsibleStaff(User $user, ItTicket $ticket): bool
    {
        $ticket = $this->canonicalTicket($ticket);
        if (! $ticket) {
            return false;
        }

        $userId = (int) $user->getKey();
        if ($userId < 1) {
            return false;
        }

        return (int) $ticket->assigned_to_user_id === $userId
            || (int) $ticket->owner_user_id === $userId
            || ($ticket->team_id !== null && $this->activeTeamIncludes((int) $ticket->team_id, $userId))
            || ($ticket->queue_id !== null && $this->activeQueueIncludes((int) $ticket->queue_id, $userId));
    }

    /**
     * Apply the exact canView predicate to a ticket query.
     *
     * @param  Builder<ItTicket>  $query
     * @return Builder<ItTicket>
     */
    public function applyViewScope(Builder $query, User $user): Builder
    {
        $userId = (int) $user->getKey();
        $hasStaffCapability = $this->hasStaffCapability($user);
        $canViewSensitive = $user->canDo('it.viewSensitive');
        $canViewOrganisationWide = $user->canDo('it.organisationWide');
        $approvedSiteIds = $this->approvedSiteIds($user);

        return $query->where(function (Builder $visible) use (
            $userId,
            $hasStaffCapability,
            $canViewSensitive,
            $canViewOrganisationWide,
            $approvedSiteIds,
        ): void {
            $visible->where(function (Builder $participant) use ($userId): void {
                $participant->where('requester_user_id', $userId)
                    ->orWhere('requested_for_user_id', $userId);
            });

            if (! $hasStaffCapability) {
                return;
            }

            $visible->orWhere(function (Builder $staff) use (
                $userId,
                $canViewSensitive,
                $canViewOrganisationWide,
                $approvedSiteIds,
            ): void {
                if (! $canViewSensitive) {
                    $staff->where(function (Builder $notSensitive): void {
                        $notSensitive->whereNull('is_sensitive')
                            ->orWhere('is_sensitive', false);
                    });
                }

                $staff->where(function (Builder $scope) use (
                    $userId,
                    $canViewOrganisationWide,
                    $approvedSiteIds,
                ): void {
                    $scope->where(function (Builder $siteWork) use ($userId, $approvedSiteIds): void {
                        $siteWork->whereNotNull('site_id')
                            ->whereHas('site', fn (Builder $site): Builder => $site
                                ->where('is_active', true)
                                ->where('archived', false)
                                ->whereNull('archived_at'))
                            ->where(function (Builder $responsibility) use ($userId, $approvedSiteIds): void {
                                if ($approvedSiteIds !== []) {
                                    $responsibility->whereIn('site_id', $approvedSiteIds);
                                } else {
                                    $responsibility->whereRaw('1 = 0');
                                }

                                $responsibility
                                    ->orWhere('assigned_to_user_id', $userId)
                                    ->orWhere('owner_user_id', $userId)
                                    ->orWhereHas('team', fn (Builder $team): Builder => $this->applyActiveTeamScope($team, $userId))
                                    ->orWhereHas('queue', function (Builder $queue) use ($userId): void {
                                        $queue->where('is_active', true)
                                            ->whereHas('team', fn (Builder $team): Builder => $this->applyActiveTeamScope($team, $userId));
                                    });
                            });
                    });

                    if ($canViewOrganisationWide) {
                        $scope->orWhere(function (Builder $wide): void {
                            $wide->whereNull('site_id')
                                ->where('is_organisation_wide', true);
                        });
                    }
                });
            });
        });
    }

    private function canonicalTicket(ItTicket $ticket): ?ItTicket
    {
        if (! $ticket->exists || ! is_numeric($ticket->getKey())) {
            return null;
        }

        return ItTicket::query()->find($ticket->getKey());
    }

    private function isParticipant(User $user, ItTicket $ticket): bool
    {
        $userId = (int) $user->getKey();

        return $userId > 0 && (
            (int) $ticket->requester_user_id === $userId
            || (int) $ticket->requested_for_user_id === $userId
        );
    }

    private function hasStaffCapability(User $user): bool
    {
        return $user->canDo('it.view') || $user->canDo('it.manage');
    }

    private function staffCanAccess(User $user, ItTicket $ticket): bool
    {
        if ($ticket->is_sensitive && ! $user->canDo('it.viewSensitive')) {
            return false;
        }

        if ($ticket->site_id === null) {
            return $ticket->is_organisation_wide
                && $user->canDo('it.organisationWide');
        }

        $siteIsOperational = Site::query()
            ->whereKey($ticket->site_id)
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->exists();

        if (! $siteIsOperational) {
            return false;
        }

        if (in_array((int) $ticket->site_id, $this->approvedSiteIds($user), true)) {
            return true;
        }

        $userId = (int) $user->getKey();
        if ((int) $ticket->assigned_to_user_id === $userId
            || (int) $ticket->owner_user_id === $userId) {
            return true;
        }

        if ($ticket->team_id !== null && $this->activeTeamIncludes((int) $ticket->team_id, $userId)) {
            return true;
        }

        return $ticket->queue_id !== null
            && $this->activeQueueIncludes((int) $ticket->queue_id, $userId);
    }

    /** @param Builder<ItTeam> $team */
    private function applyActiveTeamScope(Builder $team, int $userId): Builder
    {
        return $team->where('is_active', true)
            ->where(function (Builder $responsibility) use ($userId): void {
                $responsibility->where('manager_user_id', $userId)
                    ->orWhereHas('members', fn (Builder $members): Builder => $members->whereKey($userId));
            });
    }

    private function activeTeamIncludes(int $teamId, int $userId): bool
    {
        return ItTeam::query()
            ->whereKey($teamId)
            ->where(fn (Builder $team): Builder => $this->applyActiveTeamScope($team, $userId))
            ->exists();
    }

    private function activeQueueIncludes(int $queueId, int $userId): bool
    {
        return ItQueue::query()
            ->whereKey($queueId)
            ->where('is_active', true)
            ->whereHas('team', fn (Builder $team): Builder => $this->applyActiveTeamScope($team, $userId))
            ->exists();
    }
}
