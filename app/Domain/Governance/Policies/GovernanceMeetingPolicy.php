<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Services\ExecutiveMeetingAccessService;
use App\Models\User;

class GovernanceMeetingPolicy
{
    public function __construct(
        protected ExecutiveMeetingAccessService $executiveAccess,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'board_observer');
    }

    public function view(User $user, GovernanceMeeting $meeting): bool
    {
        return $this->executiveAccess->canViewMeeting($user, $meeting);
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

        if ($meeting->isExecutiveSession() && ! $this->executiveAccess->canViewMeeting($user, $meeting)) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }

    public function delete(User $user, GovernanceMeeting $meeting): bool
    {
        if ($meeting->isExecutiveSession() && ! $this->executiveAccess->canViewMeeting($user, $meeting)) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair');
    }

    public function manageMinutes(User $user, GovernanceMeeting $meeting): bool
    {
        if ($meeting->isExecutiveSession() && ! $this->executiveAccess->canViewMeeting($user, $meeting)) {
            return false;
        }

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
        if ($meeting->isExecutiveSession() && ! $this->executiveAccess->canViewMeeting($user, $meeting)) {
            return false;
        }

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

        if ($meeting->isExecutiveSession() && ! $this->executiveAccess->canViewMeeting($user, $meeting)) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }

    public function distributePack(User $user, GovernanceMeeting $meeting): bool
    {
        if (!$meeting->canDistributePack()) {
            return false;
        }

        if ($meeting->isExecutiveSession() && ! $this->executiveAccess->canViewMeeting($user, $meeting)) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }
}
