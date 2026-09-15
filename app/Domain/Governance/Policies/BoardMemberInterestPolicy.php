<?php

namespace App\Domain\Governance\Policies;

use App\Domain\Governance\Models\BoardMemberInterest;
use App\Models\User;

class BoardMemberInterestPolicy
{
    public function viewAny(User $user): bool { return $user->canDo('governance.interests.view'); }

    public function view(User $user, BoardMemberInterest $interest): bool { return $user->canDo('governance.interests.view'); }

    public function create(User $user): bool { return $user->canDo('governance.interests.manage'); }

    /**
     * Change or end a declaration: the member's own declaration, or someone
     * who manages the board (the people who run the Board members page).
     * Every board member can declare, so declaring alone must not let one
     * member edit another member's register entry.
     */
    public function update(User $user, BoardMemberInterest $interest): bool
    {
        if (! $user->canDo('governance.interests.manage')) {
            return false;
        }

        return $this->isOwnDeclaration($user, $interest)
            || $user->canDo('governance.meetings.manage');
    }

    public function delete(User $user, BoardMemberInterest $interest): bool
    {
        return $this->update($user, $interest);
    }

    private function isOwnDeclaration(User $user, BoardMemberInterest $interest): bool
    {
        $interest->loadMissing('boardMember');

        return $interest->boardMember !== null
            && (int) $interest->boardMember->user_id === (int) $user->id;
    }
}
