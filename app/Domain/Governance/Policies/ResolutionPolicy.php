<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\Resolution;
use App\Models\User;

class ResolutionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'board_observer', 'ceo');
    }

    public function view(User $user, Resolution $resolution): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary', 'board_member', 'board_observer', 'ceo');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary', 'ceo');
    }

    public function update(User $user, Resolution $resolution): bool
    {
        if (!$resolution->isDraft()) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair', 'board_secretary') 
            || $resolution->proposed_by === $user->id;
    }

    public function delete(User $user, Resolution $resolution): bool
    {
        if (!$resolution->isDraft()) {
            return false;
        }

        return $user->hasRole('admin', 'board_chair') 
            || $resolution->proposed_by === $user->id;
    }

    public function vote(User $user, Resolution $resolution): bool
    {
        if (!$resolution->isOpen()) {
            return false;
        }

        // Check if user is an active board member
        $boardMember = \App\Domain\Governance\Models\BoardMember::where('user_id', $user->id)
            ->active()
            ->first();

        if (!$boardMember) {
            return false;
        }

        // Check for conflict declaration
        $hasConflict = $resolution->conflictDeclarations()
            ->where('board_member_id', $boardMember->id)
            ->where('withdrew_from_voting', true)
            ->exists();

        return !$hasConflict;
    }

    public function openVoting(User $user, Resolution $resolution): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }

    public function closeVoting(User $user, Resolution $resolution): bool
    {
        return $user->hasRole('admin', 'board_chair', 'board_secretary');
    }
}
