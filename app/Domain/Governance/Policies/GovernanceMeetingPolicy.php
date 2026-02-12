<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\GovernanceMeeting;
use App\Models\User;

class GovernanceMeetingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'board_observer');
    }

    public function view(User $user, GovernanceMeeting $meeting): bool
    {
        // Board members can view all meetings
        if ($user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'board_observer')) {
            return true;
        }

        // CEO can view non-executive sessions
        if ($user->hasRole('ceo') && !$meeting->isExecutiveSession()) {
            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }

    public function update(User $user, GovernanceMeeting $meeting): bool
    {
        if (!$meeting->isEditable()) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }

    public function delete(User $user, GovernanceMeeting $meeting): bool
    {
        return $user->hasRole('admin', 'board_chair');
    }

    public function manageMinutes(User $user, GovernanceMeeting $meeting): bool
    {
        // Secretary or Chair can manage minutes
        if ($meeting->secretary_id && $user->id === $meeting->secretary?->user_id) {
            return true;
        }

        if ($meeting->chair_id && $user->id === $meeting->chair?->user_id) {
            return true;
        }

        return $user->hasRole('admin', 'board_secretary');
    }

    public function approveMinutes(User $user, GovernanceMeeting $meeting): bool
    {
        // Only Chair can approve minutes
        if ($meeting->chair_id && $user->id === $meeting->chair?->user_id) {
            return true;
        }

        return $user->hasRole('admin', 'board_chair');
    }

    public function generatePack(User $user, GovernanceMeeting $meeting): bool
    {
        if ($meeting->status === 'archived') {
            return false;
        }

        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }

    public function distributePack(User $user, GovernanceMeeting $meeting): bool
    {
        if (!$meeting->canDistributePack()) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }
}
