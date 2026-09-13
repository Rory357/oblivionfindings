<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardPack;
use App\Domain\Governance\Models\GovernanceDocument;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\PerformanceReview;
use App\Domain\Governance\Models\Resolution;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class GovernanceRecordAccessService
{
    public function __construct(
        private readonly ExecutiveMeetingAccessService $meetingAccess,
        private readonly BoardPackAccessService $packAccess,
    ) {}

    public function canViewMeeting(User $user, GovernanceMeeting $meeting): bool
    {
        return $this->meetingAccess->canViewMeeting($user, $meeting);
    }

    public function scopeMeetings(Builder $query, User $user): Builder
    {
        return $this->meetingAccess->applyMeetingVisibilityScope($query, $user);
    }

    public function canViewResolution(User $user, Resolution $resolution): bool
    {
        if (! $user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'board_observer', 'ceo')
            && ! $user->canDo('governance.resolutions.view')) {
            return false;
        }

        if ($resolution->governance_meeting_id) {
            $meeting = $resolution->relationLoaded('meeting')
                ? $resolution->meeting
                : $resolution->meeting()->first();

            if ($meeting && ! $this->canViewMeeting($user, $meeting)) {
                return false;
            }
        }

        return true;
    }

    public function scopeResolutions(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            $q->whereNull('governance_meeting_id')
                ->orWhereHas('meeting', function (Builder $mq) use ($user) {
                    $this->meetingAccess->applyMeetingVisibilityScope($mq, $user);
                });
        });
    }

    public function canViewBoardPack(User $user, BoardPack $pack): bool
    {
        return $this->packAccess->canView($user, $pack);
    }

    public function scopeBoardPacks(Builder $query, User $user): Builder
    {
        return $this->packAccess->visibleQuery($user);
    }

    public function canViewActionItem(User $user, ActionItem $action): bool
    {
        // If action item is tied to an executive session meeting, verify user can view that meeting first
        if (in_array($action->source_type, [GovernanceMeeting::class, 'meeting', 'governance_meeting']) && $action->source_id) {
            $meeting = ($action->relationLoaded('source') && $action->source instanceof GovernanceMeeting)
                ? $action->source
                : GovernanceMeeting::find($action->source_id);

            if ($meeting && ! $this->canViewMeeting($user, $meeting)) {
                return false;
            }
        }

        // If action item is tied to a resolution, verify user can view that resolution first
        if (in_array($action->source_type, [Resolution::class, 'resolution']) && $action->source_id) {
            $resolution = ($action->relationLoaded('source') && $action->source instanceof Resolution)
                ? $action->source
                : Resolution::with('meeting')->find($action->source_id);

            if ($resolution && ! $this->canViewResolution($user, $resolution)) {
                return false;
            }
        }

        if ((int) $action->assigned_to === (int) $user->id) {
            return true;
        }

        if (! $user->canDo('governance.actions.view')) {
            return false;
        }

        return true;
    }

    public function scopeActionItems(Builder $query, User $user): Builder
    {
        if ($this->meetingAccess->hasExecutiveAuthority($user)) {
            return $query;
        }

        $userId = $user->id;

        return $query->where(function (Builder $q) use ($user, $userId) {
            // Parent meeting/resolution MUST be accessible first
            $q->where(function (Builder $sub) use ($user) {
                $sub->whereNotIn('source_type', [GovernanceMeeting::class, Resolution::class, 'meeting', 'governance_meeting', 'resolution'])
                    ->orWhereNull('source_type')
                    ->orWhere(function (Builder $mQ) use ($user) {
                        $mQ->whereIn('source_type', [GovernanceMeeting::class, 'meeting', 'governance_meeting'])
                            ->whereHasMorph('source', [GovernanceMeeting::class], function (Builder $inner) use ($user) {
                                $this->meetingAccess->applyMeetingVisibilityScope($inner, $user);
                            });
                    })
                    ->orWhere(function (Builder $rQ) use ($user) {
                        $rQ->whereIn('source_type', [Resolution::class, 'resolution'])
                            ->whereHasMorph('source', [Resolution::class], function (Builder $inner) use ($user) {
                                $this->scopeResolutions($inner, $user);
                            });
                    });
            })->where(function (Builder $sub) use ($userId, $user) {
                $sub->where('assigned_to', $userId);
                if ($user->canDo('governance.actions.view')) {
                    $sub->orWhereNull('assigned_to')
                        ->orWhere('assigned_to', '!=', $userId);
                }
            });
        });
    }

    public function canViewPerformanceReview(User $user, PerformanceReview $review): bool
    {
        // Reviewee can always view their own review
        if ((int) $review->reviewee_id === (int) $user->id) {
            return true;
        }

        // Management authority or chair/admin
        if ($user->canDo('governance.performance.manage')
            || $user->hasRole('admin', 'board_chair')) {
            return true;
        }

        // Remuneration / Governance / Performance committee members
        $boardMember = $user->boardMember;
        if ($boardMember && $boardMember->is_active) {
            if ($boardMember->isCommitteeMember('remuneration')
                || $boardMember->isCommitteeMember('governance')
                || $boardMember->isCommitteeMember('executive')) {
                return true;
            }
        }

        // Ordinary board members and observers do NOT see raw performance reviews
        return false;
    }

    public function scopePerformanceReviews(Builder $query, User $user): Builder
    {
        if ($user->canDo('governance.performance.manage') || $user->hasRole('admin', 'board_chair')) {
            return $query;
        }

        $boardMember = $user->boardMember;
        if ($boardMember && $boardMember->is_active) {
            if ($boardMember->isCommitteeMember('remuneration')
                || $boardMember->isCommitteeMember('governance')
                || $boardMember->isCommitteeMember('executive')) {
                return $query;
            }
        }

        // Otherwise, only reviewee sees their own reviews
        return $query->where('reviewee_id', $user->id);
    }

    public function canViewDocument(User $user, GovernanceDocument $document): bool
    {
        return $user->canDo('governance.documents.view');
    }

    public function scopeDocuments(Builder $query, User $user): Builder
    {
        return $query;
    }
}
