<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Notifications\BoardPackPublishedNotification;
use App\Domain\Governance\Notifications\PreReadReminderNotification;
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
        $query = BoardPack::query()->whereHas('meeting');

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

        return $query
            ->whereNotNull('distributed_at')
            ->whereJsonContains('distributed_to', $boardMemberId);
    }

    public function canView(User $viewer, BoardPack $pack): bool
    {
        if (! $this->canViewPacks($viewer) || ! $this->hasMeeting($pack)) {
            return false;
        }

        return $this->canManage($viewer)
            || $this->recipientBoardMemberId($viewer, $pack) !== null;
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
