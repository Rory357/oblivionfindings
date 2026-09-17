<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Notifications\BoardPackPublishedNotification;
use App\Domain\Governance\Notifications\PreReadReminderNotification;
use App\Domain\Governance\Support\BoardPackContainedSources;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

final class BoardPackAccessService
{
    private const PROTECTED_NOTIFICATION_TYPES = [
        BoardPackPublishedNotification::class,
        PreReadReminderNotification::class,
    ];

    public function canViewPacks(User $viewer): bool
    {
        return $viewer->canDo('governance.packs.view');
    }

    public function canManage(User $viewer): bool
    {
        return $this->canViewPacks($viewer)
            && $viewer->canDo('governance.packs.manage');
    }

    /** @return Builder<BoardPack> */
    public function visibleQuery(User $viewer): Builder
    {
        $query = BoardPack::query()->whereHas('meeting', function (Builder $mq) use ($viewer) {
            app(ExecutiveMeetingAccessService::class)->applyMeetingVisibilityScope($mq, $viewer);
        });

        if (! $this->canViewPacks($viewer)) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->canManage($viewer)) {
            return $query;
        }

        $boardMemberId = $this->boardMemberId($viewer);

        if ($boardMemberId === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereNotNull('distributed_at')
            ->where(function ($q) {
                $q->whereNull('build_status')->orWhere('build_status', 'published');
            })
            ->whereJsonContains('distributed_to', $boardMemberId);

        return $this->applyContainedSourceAudience($query, $viewer, $boardMemberId);
    }

    /**
     * The typed contained-source audience contract for non-managers.
     *
     * A pack is released only when (1) its manifest has been indexed into
     * typed (source_type, integer id) rows, (2) every contained resolution
     * and supporting document is a record the viewer can open, and (3) any
     * confidential agenda is within the viewer's authority. Discovery and
     * canView/download both evaluate exactly this constraint, so they cannot
     * disagree about which records a pack embeds.
     *
     * @param  Builder<BoardPack>  $query
     * @return Builder<BoardPack>
     */
    private function applyContainedSourceAudience(Builder $query, User $viewer, int $boardMemberId): Builder
    {
        // (1) An unindexed manifest is never released to a non-manager (fail closed).
        $query->whereNotNull($query->getModel()->qualifyColumn('contained_sources_indexed_at'));

        // (2) Every embedded record must be individually visible.
        $recordAccess = app(GovernanceRecordAccessService::class);
        $this->excludeHiddenSources(
            $query,
            BoardPackContainedSources::RESOLUTION,
            $recordAccess->canViewAnyResolution($viewer)
                ? $recordAccess->scopeResolutions(Resolution::query(), $viewer)->select((new Resolution)->qualifyColumn('id'))
                : null,
        );
        $this->excludeHiddenSources(
            $query,
            BoardPackContainedSources::GOVERNANCE_DOCUMENT,
            $recordAccess->canViewAnyDocument($viewer)
                ? $recordAccess->scopeDocuments(GovernanceDocument::query(), $viewer)->select((new GovernanceDocument)->qualifyColumn('id'))
                : null,
        );

        // (3) Confidential agenda content requires executive, chair/secretary or current committee authority.
        $execAccess = app(ExecutiveMeetingAccessService::class);
        if (! $execAccess->hasExecutiveAuthority($viewer)) {
            $today = today()->toDateString();
            $query->where(function (Builder $q) use ($boardMemberId, $today) {
                $q->where($q->getModel()->qualifyColumn('contains_confidential_agenda'), false)->orWhereHas('meeting', function (Builder $mq) use ($boardMemberId, $today) {
                    $mq->where(function (Builder $mSub) use ($boardMemberId, $today) {
                        $mSub->where('chair_id', $boardMemberId)
                            ->orWhere('secretary_id', $boardMemberId)
                            ->orWhere(function (Builder $comm) use ($boardMemberId, $today) {
                                $comm->whereNotNull('board_committee_id')
                                    ->whereHas('committee.members', function (Builder $cm) use ($boardMemberId, $today) {
                                        $cm->where('board_members.id', $boardMemberId)
                                            ->where('committee_memberships.is_active', true)
                                            ->where(function ($term) use ($today) {
                                                $term->whereNull('committee_memberships.appointed_at')
                                                    ->orWhereDate('committee_memberships.appointed_at', '<=', $today);
                                            })
                                            ->where(function ($term) use ($today) {
                                                $term->whereNull('committee_memberships.term_end')
                                                    ->orWhereDate('committee_memberships.term_end', '>=', $today);
                                            });
                                    });
                            });
                    });
                });
            });
        }

        return $query;
    }

    /**
     * Exclude packs containing any source of $type whose id is not in the
     * viewer's visible set. A null set means the viewer can see none, so any
     * contained source of that type hides the pack. Ids are compared as typed
     * integers — never as manifest text — so id 2 cannot collide with 2999.
     *
     * @param  Builder<BoardPack>  $query
     * @param  Builder<\Illuminate\Database\Eloquent\Model>|null  $visibleIds
     */
    private function excludeHiddenSources(Builder $query, string $type, ?Builder $visibleIds): void
    {
        $query->whereDoesntHave('containedSources', function (Builder $sources) use ($type, $visibleIds): void {
            $sources->where('source_type', $type);

            if ($visibleIds !== null) {
                $sources->whereNotIn('source_id', $visibleIds);
            }
        });
    }

    public function canView(User $viewer, BoardPack $pack): bool
    {
        if (! $pack->exists || ! $this->canViewPacks($viewer) || ! $this->hasMeeting($pack)) {
            return false;
        }

        $meeting = $pack->relationLoaded('meeting') ? $pack->meeting : $pack->meeting()->first();
        if ($meeting && ! app(ExecutiveMeetingAccessService::class)->canViewMeeting($viewer, $meeting)) {
            return false;
        }

        if ($this->canManage($viewer)) {
            return true;
        }

        if (($pack->build_status ?? 'published') !== 'published') {
            return false;
        }

        if ($this->recipientBoardMemberId($viewer, $pack) === null) {
            return false;
        }

        // Audience safety: the single record passes only if discovery would
        // return it, so the typed contained-source contract (confidential
        // agenda, embedded papers and documents) is evaluated exactly once.
        return $this->visibleQuery($viewer)->whereKey($pack->getKey())->exists();
    }

    public function visiblePack(User $viewer, ?BoardPack $pack): ?BoardPack
    {
        return $pack !== null && $this->canView($viewer, $pack) ? $pack : null;
    }

    public function concealUnlessVisible(User $viewer, BoardPack $pack): void
    {
        abort_unless($this->canView($viewer, $pack), 404);
    }

    public function recipientBoardMemberId(User $viewer, BoardPack $pack): ?int
    {
        if (! $pack->isDistributed()) {
            return null;
        }

        $boardMemberId = $this->boardMemberId($viewer);

        if ($boardMemberId === null) {
            return null;
        }

        $recipientIds = array_map('intval', $pack->distributed_to ?? []);

        return in_array($boardMemberId, $recipientIds, true) ? $boardMemberId : null;
    }

    /** @return MorphMany<DatabaseNotification, User> */
    public function visibleNotificationQuery(User $viewer, bool $unreadOnly = false): MorphMany
    {
        $visiblePacks = $this->visibleQuery($viewer);
        $visiblePackIds = (clone $visiblePacks)
            ->select($visiblePacks->getModel()->qualifyColumn('id'));
        $visibleMeetingIds = (clone $visiblePacks)
            ->select($visiblePacks->getModel()->qualifyColumn('governance_meeting_id'));

        $query = $unreadOnly
            ? $viewer->unreadNotifications()
            : $viewer->notifications();

        return $query->where(function (Builder $notifications) use ($visiblePackIds, $visibleMeetingIds): void {
            $notifications
                ->whereNotIn('type', self::PROTECTED_NOTIFICATION_TYPES)
                ->orWhere(function (Builder $packNotifications) use ($visiblePackIds): void {
                    $packNotifications
                        ->where('type', BoardPackPublishedNotification::class)
                        ->whereIn('data->pack_id', $visiblePackIds);
                })
                ->orWhere(function (Builder $reminders) use ($visibleMeetingIds): void {
                    $reminders
                        ->where('type', PreReadReminderNotification::class)
                        ->whereIn('data->meeting_id', $visibleMeetingIds);
                });
        });
    }

    public function scopeAuditVisibility(Builder $query, ?User $viewer): Builder
    {
        if ($viewer && $this->canManage($viewer)) {
            return $query;
        }

        return $query
            ->where(function (Builder $nonPack): void {
                $nonPack->whereNull('auditable_type')
                    ->orWhereNotIn('auditable_type', [BoardPack::class, 'BoardPack']);
            })
            ->where(function (Builder $nonPackAction): void {
                $nonPackAction->whereNull('action')
                    ->orWhere(function (Builder $action): void {
                        $action->where('action', 'not like', 'boardpack.%')
                            ->where('action', 'not like', 'board_pack.%');
                    });
            });
    }

    private function boardMemberId(User $viewer): ?int
    {
        $id = BoardMember::query()
            ->active()
            ->where('user_id', $viewer->id)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function hasMeeting(BoardPack $pack): bool
    {
        if ($pack->relationLoaded('meeting')) {
            return $pack->meeting !== null;
        }

        return $pack->meeting()->exists();
    }
}
