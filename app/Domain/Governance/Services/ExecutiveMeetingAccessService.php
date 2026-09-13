<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class ExecutiveMeetingAccessService
{
    public function hasExecutiveAuthority(User $user): bool
    {
        return $user->canDo('governance.executive.view')
            || $user->hasRole('admin', 'board_chair');
    }

    public function canViewMeeting(User $user, GovernanceMeeting $meeting): bool
    {
        if (! $meeting->isExecutiveSession()) {
            if ($user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'board_observer')) {
                return true;
            }

            if ($user->canDo('governance.meetings.view')) {
                return true;
            }

            if ($user->hasRole('ceo') && ! $meeting->isExecutiveSession()) {
                return true;
            }

            return false;
        }

        // For executive-session meetings:
        if ($this->hasExecutiveAuthority($user)) {
            return true;
        }

        if ((int) $meeting->created_by === (int) $user->id) {
            return true;
        }

        $boardMember = $user->boardMember;
        if (! $boardMember || ! $boardMember->is_active) {
            return false;
        }

        if ((int) $meeting->chair_id === (int) $boardMember->id || (int) $meeting->secretary_id === (int) $boardMember->id) {
            return true;
        }

        $hasPresentAttendance = $meeting->attendances()
            ->where('board_member_id', $boardMember->id)
            ->where('status', 'present')
            ->whereNotNull('marked_by')
            ->exists();
        if ($hasPresentAttendance) {
            return true;
        }

        if ($meeting->board_committee_id) {
            if ($boardMember->committeeMemberships()
                ->where('board_committee_id', $meeting->board_committee_id)
                ->where('is_active', true)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    public function canViewAgendaItem(User $user, GovernanceMeeting $meeting, MeetingAgendaItem $item): bool
    {
        if (! $this->canViewMeeting($user, $meeting)) {
            return false;
        }

        if (! $item->is_confidential) {
            return true;
        }

        // Item is confidential / sensitive
        if ($this->hasExecutiveAuthority($user)) {
            return true;
        }

        if ((int) $item->presenter_id === (int) $user->id) {
            return true;
        }

        if ($meeting->isExecutiveSession()) {
            // Already verified via canViewMeeting that user has executive/attendee access
            return true;
        }

        $boardMember = $user->boardMember;
        if (! $boardMember || ! $boardMember->is_active) {
            return false;
        }

        if ((int) $meeting->chair_id === (int) $boardMember->id || (int) $meeting->secretary_id === (int) $boardMember->id) {
            return true;
        }

        if ($meeting->board_committee_id) {
            if ($boardMember->committeeMemberships()
                ->where('board_committee_id', $meeting->board_committee_id)
                ->where('is_active', true)
                ->exists()) {
                return true;
            }
        }

        return false;
    }

    public function canManageConfidentialAgenda(User $user, GovernanceMeeting $meeting): bool
    {
        if ($this->hasExecutiveAuthority($user)) {
            return true;
        }

        $boardMember = $user->boardMember;
        if ($boardMember && ((int) $meeting->chair_id === (int) $boardMember->id || (int) $meeting->secretary_id === (int) $boardMember->id)) {
            return true;
        }

        if ($meeting->isExecutiveSession() && $this->canViewMeeting($user, $meeting) && $user->canDo('governance.meetings.manage')) {
            return true;
        }

        return false;
    }

    public function applyMeetingVisibilityScope(Builder $query, User $user): Builder
    {
        if ($this->hasExecutiveAuthority($user)) {
            return $query;
        }

        $boardMember = $user->boardMember;
        $boardMemberId = $boardMember?->id;
        $isActive = $boardMember?->is_active ?? false;

        return $query->where(function (Builder $q) use ($user, $boardMemberId, $isActive) {
            $q->where('meeting_type', '!=', 'executive_session')
                ->orWhere(function (Builder $execQuery) use ($user, $boardMemberId, $isActive) {
                    $execQuery->where('meeting_type', 'executive_session')
                        ->where(function (Builder $allowed) use ($user, $boardMemberId, $isActive) {
                            $allowed->where('created_by', $user->id);

                            if ($boardMemberId !== null && $isActive) {
                                $allowed->orWhere('chair_id', $boardMemberId)
                                    ->orWhere('secretary_id', $boardMemberId)
                                    ->orWhereHas('attendances', fn (Builder $att) => $att
                                        ->where('board_member_id', $boardMemberId)
                                        ->where('status', 'present')
                                        ->whereNotNull('marked_by')
                                    )
                                    ->orWhereHas('committee.members', fn (Builder $cm) => $cm
                                        ->where('board_members.id', $boardMemberId)
                                        ->where('committee_memberships.is_active', true)
                                    );
                            }
                        });
                });
        });
    }
}
