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

        if ($boardMember->isCommitteeMember('executive') || $boardMember->isCommitteeMember('executive_session')) {
            return true;
        }

        if ($meeting->relationLoaded('attendances')) {
            if ($meeting->attendances->contains(fn ($att) => (int) $att->board_member_id === (int) $boardMember->id)) {
                return true;
            }
        } elseif ($meeting->attendances()->where('board_member_id', $boardMember->id)->exists()) {
            return true;
        }

        if ($meeting->relationLoaded('rsvps')) {
            if ($meeting->rsvps->contains(fn ($rsvp) => (int) $rsvp->board_member_id === (int) $boardMember->id)) {
                return true;
            }
        } elseif ($meeting->rsvps()->where('board_member_id', $boardMember->id)->exists()) {
            return true;
        }

        if ($meeting->board_committee_id) {
            if ($meeting->relationLoaded('committee') && $meeting->committee) {
                if ($boardMember->isCommitteeMember($meeting->committee->committee_type)) {
                    return true;
                }
            } elseif ($meeting->committee()->whereHas('members', function ($query) use ($boardMember) {
                $query->where('board_members.id', $boardMember->id)
                    ->where('committee_memberships.is_active', true);
            })->exists()) {
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

        if ($boardMember->isCommitteeMember('executive') || $boardMember->isCommitteeMember('executive_session')) {
            return true;
        }

        if ($meeting->relationLoaded('attendances')) {
            if ($meeting->attendances->contains(fn ($att) => (int) $att->board_member_id === (int) $boardMember->id)) {
                return true;
            }
        } elseif ($meeting->attendances()->where('board_member_id', $boardMember->id)->exists()) {
            return true;
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
        if ($boardMember && ($boardMember->isCommitteeMember('executive') || $boardMember->isCommitteeMember('executive_session'))) {
            return $query;
        }

        $boardMemberId = $boardMember?->id;

        return $query->where(function (Builder $q) use ($user, $boardMemberId) {
            $q->where('meeting_type', '!=', 'executive_session')
                ->orWhere(function (Builder $execQuery) use ($user, $boardMemberId) {
                    $execQuery->where('meeting_type', 'executive_session')
                        ->where(function (Builder $allowed) use ($user, $boardMemberId) {
                            $allowed->where('created_by', $user->id);

                            if ($boardMemberId !== null) {
                                $allowed->orWhere('chair_id', $boardMemberId)
                                    ->orWhere('secretary_id', $boardMemberId)
                                    ->orWhereHas('attendances', fn (Builder $att) => $att->where('board_member_id', $boardMemberId))
                                    ->orWhereHas('rsvps', fn (Builder $rsvp) => $rsvp->where('board_member_id', $boardMemberId))
                                    ->orWhereHas('committee.members', fn (Builder $cm) => $cm->where('board_members.id', $boardMemberId)->where('committee_memberships.is_active', true));
                            }
                        });
                });
        });
    }
}
